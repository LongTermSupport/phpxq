<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Navigation into mappings and sequences: key and index lookup with `*` globs, `<<` merge keys (both
 * the legacy and the spec-fixed resolution order), splat, recursive descent and the null placeholders
 * (see {@see Detached}) for paths that do not exist.
 */
final class Traversal
{
    private const int MAX_MERGE_DEPTH = 32;

    private function __construct()
    {
    }

    /**
     * `.name`, `.[name]` and `.[index]` over one base match.
     *
     * @return list<Candidate>
     *
     * @throws EvaluationException
     */
    public static function field(Candidate $base, Node $key, bool $fixedMerge, bool $autoCreate, bool $optional): array
    {
        $base = Cands::rooted($base);
        $node = NodeOps::deref($base->node);

        switch ($node->kind) {
            case NodeKind::Mapping:
                return self::mappingField($base, $node, $key, $fixedMerge, $autoCreate);

            case NodeKind::Sequence:
                $name = $key->value;
                if (1 === preg_match('/^-?[0-9]+$/D', $name)) {
                    return self::index($base, $node, (int)$name, $autoCreate);
                }

                if ($optional) {
                    return [];
                }

                if (str_contains($name, '*')) {
                    return [];
                }

                throw new EvaluationException(\sprintf("Cannot index array with '%s'", $name));
            case NodeKind::Scalar:
                if (CoreSchema::TAG_NULL === $node->tag && $autoCreate && !str_contains($key->value, '*')) {
                    return [self::placeholder($base, $key)];
                }

                return [];

            default:
                return [];
        }
    }

    /**
     * @return list<Candidate>
     *
     * @throws EvaluationException
     */
    public static function index(Candidate $base, Node $seq, int $index, bool $autoCreate): array
    {
        $count = \count($seq->content);
        if ($index < 0) {
            $index += $count;
            if ($index < 0) {
                if ($autoCreate) {
                    throw new EvaluationException(\sprintf('Index [%d] out of range, array size is %d', $index - $count, $count));
                }

                return [];
            }
        }

        if ($index < $count) {
            return [Cands::child($seq->content[$index], $base, NodeOps::int($index))];
        }

        if (!$autoCreate) {
            return [];
        }

        $value = NodeOps::null();
        Detached::mark($value);

        return [Cands::child($value, $base, NodeOps::int($index))];
    }

    /**
     * Key lookup in a mapping, following merge keys. Returns [keyNode, valueNode] pairs.
     *
     * @return list<array{Node, Node}>
     */
    public static function lookup(Node $map, string $name, bool $glob, bool $fixedMerge): array
    {
        $content = $map->content;
        $count   = \count($content);
        $matches = [];
        $merge   = false;
        for ($i = 0; $i < $count; $i += 2) {
            $key = $content[$i];
            if ('<<' !== $name && NodeOps::isMergeKey($key)) {
                $merge = true;

                continue;
            }

            if ($glob ? Compare::glob($key->value, $name) : $key->value === $name) {
                $matches[] = [$key, $content[$i + 1]];
            }
        }

        if (!$merge) {
            return $matches;
        }

        $matches = [];
        foreach (self::entries($map, $fixedMerge) as $pair) {
            if ($glob ? Compare::glob($pair[0]->value, $name) : $pair[0]->value === $name) {
                $matches[] = $pair;
            }
        }

        return $matches;
    }

    /**
     * Every entry of a mapping with merge keys expanded, in the order the reference yields them.
     *
     * Legacy resolution walks the entries in order and lets later writes replace earlier values while
     * keeping the first position; a merge key expands its targets in list order (traversal) or reversed
     * (`explode`). The spec-fixed resolution writes the merged entries first (later list items first) and
     * then lets the mapping's own keys win.
     *
     * @return array<int|string, array{Node, Node}>
     */
    public static function entries(Node $map, bool $fixedMerge, bool $reverseTargets = false): array
    {
        $out = [];
        self::collect($map, $fixedMerge, $reverseTargets, $out, 0);

        return $out;
    }

    /**
     * The mappings a `<<` value merges in: an alias or a mapping, or a sequence of them.
     *
     * @return list<Node>
     */
    public static function mergeTargets(Node $value): array
    {
        $value = NodeOps::deref($value);
        if (NodeKind::Mapping === $value->kind) {
            return [$value];
        }

        $targets = [];
        if (NodeKind::Sequence === $value->kind) {
            foreach ($value->content as $item) {
                $item = NodeOps::deref($item);
                if (NodeKind::Mapping === $item->kind) {
                    $targets[] = $item;
                }
            }
        }

        return $targets;
    }

    /**
     * Every value of a mapping or item of a sequence.
     *
     * @return list<Candidate>
     */
    public static function values(Candidate $base, bool $fixedMerge): array
    {
        $base = Cands::rooted($base);
        $node = NodeOps::deref($base->node);
        $out  = [];
        if (NodeKind::Mapping === $node->kind) {
            $content = $node->content;
            $count   = \count($content);
            $merge   = false;
            for ($i = 0; $i < $count; $i += 2) {
                if (NodeOps::isMergeKey($content[$i])) {
                    $merge = true;

                    break;
                }
            }

            if (!$merge) {
                for ($i = 0; $i < $count; $i += 2) {
                    $out[] = Cands::child($content[$i + 1], $base, $content[$i]);
                }

                return $out;
            }

            foreach (self::entries($node, $fixedMerge) as [$key, $value]) {
                $out[] = Cands::child($value, $base, $key);
            }

            return $out;
        }

        if (NodeKind::Sequence === $node->kind) {
            foreach ($node->content as $index => $item) {
                $out[] = Cands::child($item, $base, NodeOps::int($index));
            }
        }

        return $out;
    }

    /**
     * `..` and `...`: the node, then its descendants depth first. Aliases are not followed and merge
     * entries are not expanded.
     *
     * @param list<Candidate> $out
     */
    public static function descend(Candidate $candidate, bool $includeKeys, array &$out): void
    {
        $candidate = Cands::rooted($candidate);
        $out[]     = $candidate;
        $node      = $candidate->node;
        if (NodeKind::Mapping === $node->kind) {
            $content = $node->content;
            $count   = \count($content);
            for ($i = 0; $i < $count; $i += 2) {
                $key = $content[$i];
                if ($includeKeys) {
                    $out[] = new Candidate($key, $candidate, $key, $candidate->documentIndex, $candidate->fileIndex, $candidate->filename);
                } elseif (NodeOps::isMergeKey($key)) {
                    self::descend(Cands::child($content[$i + 1], $candidate, $key), $includeKeys, $out);

                    continue;
                }

                self::descend(Cands::child($content[$i + 1], $candidate, $key), $includeKeys, $out);
            }
        } elseif (NodeKind::Sequence === $node->kind) {
            foreach ($node->content as $index => $item) {
                self::descend(Cands::child($item, $candidate, NodeOps::int($index)), $includeKeys, $out);
            }
        }
    }

    /**
     * @return list<Candidate>
     */
    private static function mappingField(Candidate $base, Node $map, Node $key, bool $fixedMerge, bool $autoCreate): array
    {
        $name   = $key->value;
        $glob   = str_contains($name, '*');
        $found  = self::lookup($map, $name, $glob, $fixedMerge);
        $result = [];
        foreach ($found as [$keyNode, $valueNode]) {
            $result[] = Cands::child($valueNode, $base, $keyNode);
        }

        if ([] !== $result || !$autoCreate || $glob) {
            return $result;
        }

        return [self::placeholder($base, $key)];
    }

    /**
     * A detached null for a key that is not there; the first mutation attaches it.
     */
    private static function placeholder(Candidate $base, Node $key): Candidate
    {
        $value = NodeOps::null();
        Detached::mark($value);

        $keyNode = CoreSchema::TAG_INT === $key->tag ? NodeOps::int((int)$key->value) : NodeOps::str($key->value);

        return Cands::child($value, $base, $keyNode);
    }

    /**
     * @param array<int|string, array{Node, Node}> $out
     */
    private static function collect(Node $map, bool $fixedMerge, bool $reverseTargets, array &$out, int $depth): void
    {
        if ($depth > self::MAX_MERGE_DEPTH) {
            return;
        }

        $content = $map->content;
        $count   = \count($content);
        if ($fixedMerge) {
            for ($i = 0; $i < $count; $i += 2) {
                if (NodeOps::isMergeKey($content[$i])) {
                    foreach (array_reverse(self::mergeTargets($content[$i + 1])) as $target) {
                        self::collect($target, $fixedMerge, $reverseTargets, $out, $depth + 1);
                    }
                }
            }

            for ($i = 0; $i < $count; $i += 2) {
                if (!NodeOps::isMergeKey($content[$i])) {
                    $out[$content[$i]->value] = [$content[$i], $content[$i + 1]];
                }
            }

            return;
        }

        for ($i = 0; $i < $count; $i += 2) {
            if (NodeOps::isMergeKey($content[$i])) {
                $targets = self::mergeTargets($content[$i + 1]);
                if ($reverseTargets) {
                    $targets = array_reverse($targets);
                }

                foreach ($targets as $target) {
                    self::collect($target, $fixedMerge, $reverseTargets, $out, $depth + 1);
                }

                continue;
            }

            $out[$content[$i]->value] = [$content[$i], $content[$i + 1]];
        }
    }
}
