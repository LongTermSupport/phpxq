<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * The budget for expanding aliases, after go-yaml's "excessive aliasing" check.
 *
 * Aliases stay references in the tree, so a few hundred bytes of nested aliases (`a1: [*a0, *a0, ...]`, level
 * upon level) stand for a document many orders of magnitude larger. Anything that writes aliases out as copies
 * (the non-YAML encoders, `explode`) asks this first. A document is excessive when more than 100 nodes would come
 * out of aliases, more than 1000 nodes would come out in all, and the share that comes out of aliases is above
 * the allowed ratio: 0.99 up to 400,000 nodes, falling linearly to 0.10 at 4,000,000 and above.
 *
 * The count never expands anything: each node's expanded size is computed once and reused for every alias of it,
 * with an explicit stack, so the check is linear in the tree and safe on any depth. A cyclic alias counts as one
 * node at the point it re-enters; the walkers' own cycle and depth guards deal with it.
 */
final readonly class AliasExpansion
{
    /** The error text, as go-yaml words it. */
    public const string ERROR = 'document contains excessive aliasing';

    /** Up to this many aliased nodes nothing is excessive. */
    private const int MIN_ALIASED = 100;

    /** Up to this many nodes in all nothing is excessive. */
    private const int MIN_TOTAL = 1000;

    /** Up to this many nodes in all, the aliased share may reach RATIO_AT_LOW. */
    private const int RANGE_LOW = 400000;

    /** From this many nodes in all, the aliased share may reach only RATIO_AT_HIGH. */
    private const int RANGE_HIGH = 4000000;

    /** The aliased share allowed up to RANGE_LOW nodes. */
    private const float RATIO_AT_LOW = 0.99;

    /** The aliased share allowed from RANGE_HIGH nodes. */
    private const float RATIO_AT_HIGH = 0.10;

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

        $total   = self::expandedSize($root);
        $aliased = $total - $tree;
        if ($aliased <= self::MIN_ALIASED || $total <= self::MIN_TOTAL) {
            return false;
        }

        return $aliased / $total > self::allowedRatio($total);
    }

    private static function allowedRatio(int $total): float
    {
        if ($total <= self::RANGE_LOW) {
            return self::RATIO_AT_LOW;
        }

        if ($total >= self::RANGE_HIGH) {
            return self::RATIO_AT_HIGH;
        }

        return self::RATIO_AT_LOW - (self::RATIO_AT_LOW - self::RATIO_AT_HIGH) * ($total - self::RANGE_LOW) / (self::RANGE_HIGH - self::RANGE_LOW);
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
     * What a node writes out: an alias its target, anything else its content.
     *
     * @return list<Node>
     */
    private static function children(Node $node): array
    {
        if (NodeKindEnum::Alias === $node->kind) {
            return $node->aliasTarget instanceof Node ? [$node->aliasTarget] : [];
        }

        return $node->content;
    }
}
