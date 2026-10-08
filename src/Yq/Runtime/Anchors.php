<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\AliasExpansion;
use LTS\PhpXq\Yaml\MergeSources;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;

/**
 * Anchor and alias resolution: `explode` (replace aliases by copies, drop anchors, expand `<<` merge
 * keys) and anchor lookup for the `alias` setter.
 */
final readonly class Anchors
{
    private const int MAX_DEPTH = 200;

    private const string MERGE_TOO_DEEP = 'merge keys are nested too deeply';

    private function __construct()
    {
    }

    /**
     * Depth-first search for the node that carries `&name`.
     */
    public static function find(Node $root, string $name, int $depth = 0): ?Node
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if ($root->anchor === $name && '' !== $name) {
            return $root;
        }

        foreach ($root->content as $child) {
            $found = self::find($child, $name, $depth + 1);
            if ($found instanceof Node) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Rewrites the tree in place so it holds no aliases, anchors or merge keys.
     *
     * @throws EvaluationException when expanding the aliases would be excessive (an alias bomb)
     */
    public static function explode(Node $node, bool $fixedMerge, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if (0 === $depth && AliasExpansion::isExcessive($node)) {
            throw new EvaluationException(AliasExpansion::ERROR);
        }

        if (NodeKindEnum::Alias === $node->kind) {
            self::replaceAlias($node, $fixedMerge, $depth);

            return;
        }

        $node->anchor = '';

        if (NodeKindEnum::Mapping === $node->kind) {
            self::resolveMerges($node, $fixedMerge);
        }

        foreach ($node->content as $child) {
            self::explode($child, $fixedMerge, $depth + 1);
        }
    }

    private static function replaceAlias(Node $alias, bool $fixedMerge, int $depth): void
    {
        $target = NodeOps::deref($alias);
        if (NodeKindEnum::Alias === $target->kind) {
            return;
        }

        $copy = $target->deepCopy();
        self::explode($copy, $fixedMerge, $depth + 1);
        $alias->kind        = $copy->kind;
        $alias->tag         = $copy->tag;
        $alias->tagExplicit = $copy->tagExplicit;
        $alias->style       = $copy->style;
        $alias->value       = $copy->value;
        $alias->content     = $copy->content;
        $alias->aliasTarget = null;
        $alias->anchor      = '';
        if (NodeKindEnum::Alias !== $alias->kind && NodeStyleEnum::Default === $alias->style && '' === $alias->value && [] === $alias->content) {
            $alias->style = $copy->style;
        }
    }

    private static function resolveMerges(Node $map, bool $fixedMerge): void
    {
        $hasMerge = false;
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            if (NodeOps::isMergeKey($map->content[$i])) {
                $hasMerge = true;

                break;
            }
        }

        if (!$hasMerge) {
            return;
        }

        $merging      = [];
        $map->content = $fixedMerge ? self::fixedPairs($map, $merging, 0) : self::legacyPairs($map);
    }

    /**
     * @return list<Node>
     */
    private static function legacyPairs(Node $map): array
    {
        $flat  = [];
        $local = [];
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            if (!NodeOps::isMergeKey($map->content[$i])) {
                $local[$map->content[$i]->value] = true;
            }
        }

        foreach (Traversal::entries($map, false, true) as [$key, $value]) {
            $own    = isset($local[$key->value]) && self::owns($map, $key);
            $flat[] = $own ? $key : $key->deepCopy();
            $flat[] = $own ? $value : $value->deepCopy();
        }

        return $flat;
    }

    private static function owns(Node $map, Node $key): bool
    {
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            if ($map->content[$i] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, true> $merging the mappings whose expansion is under way, by object id: a merge target met
     *                                  again while it is being expanded (`a: &a {<<: *a}`) is a cycle whose keys
     *                                  are already being taken, so it is skipped
     *
     * @return list<Node>
     *
     * @throws EvaluationException when merge keys reach through more mappings than a document may nest
     */
    private static function fixedPairs(Node $map, array &$merging, int $depth): array
    {
        if ($depth > Node::maxDepth()) {
            throw new EvaluationException(self::MERGE_TOO_DEEP);
        }

        $merging[spl_object_id($map)] = true;
        $local                        = [];
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            if (!NodeOps::isMergeKey($map->content[$i])) {
                $local[$map->content[$i]->value] = true;
            }
        }

        $flat = [];
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            $key = $map->content[$i];
            if (!NodeOps::isMergeKey($key)) {
                $flat[] = $key;
                $flat[] = $map->content[$i + 1];

                continue;
            }

            $seen = [];
            foreach (MergeSources::of($map->content[$i + 1]) as $target) {
                if (isset($merging[spl_object_id($target)])) {
                    continue;
                }

                $targetPairs = self::hasMergeKey($target) ? self::fixedPairs($target, $merging, $depth + 1) : $target->content;
                for ($j = 0, $m = \count($targetPairs); $j < $m; $j += 2) {
                    $name = $targetPairs[$j]->value;
                    if (isset($local[$name]) || isset($seen[$name])) {
                        continue;
                    }

                    $seen[$name] = true;
                    $flat[]      = $targetPairs[$j]->deepCopy();
                    $flat[]      = $targetPairs[$j + 1]->deepCopy();
                }
            }
        }

        unset($merging[spl_object_id($map)]);

        return $flat;
    }

    private static function hasMergeKey(Node $map): bool
    {
        for ($i = 0, $n = \count($map->content); $i < $n; $i += 2) {
            if (NodeOps::isMergeKey($map->content[$i])) {
                return true;
            }
        }

        return false;
    }
}
