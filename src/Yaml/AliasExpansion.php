<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * The budget for expanding aliases: what is refused with go-yaml's "excessive aliasing" error.
 *
 * Aliases stay references in the tree, so a few hundred bytes of nested aliases (`a1: [*a0, *a0, ...]`, level
 * upon level) stand for a document many orders of magnitude larger. Anything that writes aliases out as copies
 * (the non-YAML encoders, `explode`) asks this first. The budget judges the real output amplification: a document
 * is excessive when it would write out more than MIN_TOTAL nodes and either more than AMPLIFICATION times the
 * nodes of its tree or more than MAX_TOTAL in all. Templates that reuse a base thousands of times stay well under
 * it; a bomb passes it after a handful of levels.
 *
 * The count never expands anything: each node's expanded size is computed once and reused for every alias of it,
 * with an explicit stack, so the check is linear in the tree and safe on any depth. A merge key counts each of its
 * sources once, however often the value repeats it, as merging takes each key once. A cyclic alias counts as one
 * node at the point it re-enters; the walkers' own cycle guards deal with it.
 */
final readonly class AliasExpansion
{
    /** The error text, as go-yaml words it. */
    public const string ERROR = 'document contains excessive aliasing';

    /** Up to this many nodes written out nothing is excessive. */
    private const int MIN_TOTAL = 1_000_000;

    /** How many times its own tree a document may write out. */
    private const int AMPLIFICATION = 1000;

    /** The most nodes a document may write out, whatever its tree. */
    private const int MAX_TOTAL = 100_000_000;

    /** The key that merges mappings in. */
    private const string MERGE_KEY = '<<';

    private function __construct()
    {
    }

    /**
     * Whether writing the node out with every alias replaced by a copy of its target would exceed the budget.
     */
    public static function isExcessive(Node $root): bool
    {
        [$tree, $hasAlias] = self::treeSize($root);
        if (!$hasAlias) {
            return false;
        }

        $total = self::expandedSize($root);
        if ($total <= self::MIN_TOTAL) {
            return false;
        }

        return $total > self::MAX_TOTAL || $total / $tree > self::AMPLIFICATION;
    }

    /**
     * The number of nodes in the tree with aliases left as they are, and whether it holds an alias.
     *
     * @return array{int, bool}
     */
    private static function treeSize(Node $root): array
    {
        $count    = 0;
        $hasAlias = false;
        $pending  = [$root];
        while ([] !== $pending) {
            $node = array_pop($pending);
            ++$count;
            if (NodeKindEnum::Alias === $node->kind) {
                $hasAlias = true;

                continue;
            }

            foreach ($node->content as $child) {
                $pending[] = $child;
            }
        }

        return [$count, $hasAlias];
    }

    /**
     * The number of nodes the tree writes out with every alias replaced by a copy of its target, the alias node
     * itself included as go-yaml counts it; saturates at PHP_INT_MAX.
     */
    private static function expandedSize(Node $root): int
    {
        /** @var array<int, int> $sizes expanded size by object id, once known */
        $sizes = [];

        /** @var array<int, true> $open nodes whose children are still being counted, by object id */
        $open = [];

        /** @var list<array{Node, bool}> $pending each node with whether its children have been scheduled */
        $pending = [[$root, false]];
        while ([] !== $pending) {
            [$node, $scheduled] = array_pop($pending);
            $id                 = spl_object_id($node);
            if (isset($sizes[$id])) {
                continue;
            }

            $children = self::children($node);
            if (!$scheduled) {
                if (isset($open[$id])) {
                    continue;
                }

                $open[$id] = true;
                $pending[] = [$node, true];
                foreach ($children as $child) {
                    $childId = spl_object_id($child);
                    if (!isset($sizes[$childId]) && !isset($open[$childId])) {
                        $pending[] = [$child, false];
                    }
                }

                continue;
            }

            $size = 1;
            foreach ($children as $child) {
                $childSize = $sizes[spl_object_id($child)] ?? 1;
                $size      = $size > PHP_INT_MAX - $childSize ? PHP_INT_MAX : $size + $childSize;
            }

            $sizes[$id] = $size;
            unset($open[$id]);
        }

        return $sizes[spl_object_id($root)] ?? 1;
    }

    /**
     * What a node writes out: an alias its target, a mapping its keys and values with each merge key's distinct
     * sources in place of its value, anything else its content.
     *
     * @return list<Node>
     */
    private static function children(Node $node): array
    {
        if (NodeKindEnum::Alias === $node->kind) {
            return $node->aliasTarget instanceof Node ? [$node->aliasTarget] : [];
        }

        if (NodeKindEnum::Mapping !== $node->kind) {
            return $node->content;
        }

        $children = [];
        for ($i = 0, $n = \count($node->content); $i < $n; $i += 2) {
            $key        = $node->content[$i];
            $children[] = $key;
            $value      = $node->content[$i + 1] ?? null;
            if (!$value instanceof Node) {
                continue;
            }

            if (NodeKindEnum::Scalar !== $key->kind || self::MERGE_KEY !== $key->value || NodeStyleEnum::Default !== $key->style) {
                $children[] = $value;

                continue;
            }

            $sources = [];
            foreach (NodeKindEnum::Sequence === $value->kind ? $value->content : [$value] as $source) {
                $source                             = NodeKindEnum::Alias === $source->kind && $source->aliasTarget instanceof Node ? $source->aliasTarget : $source;
                $sources[spl_object_id($source)]    = $source;
            }

            array_push($children, ...array_values($sources));
        }

        return $children;
    }
}
