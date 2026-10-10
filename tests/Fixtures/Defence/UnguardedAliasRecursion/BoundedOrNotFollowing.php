<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\UnguardedAliasRecursion;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Recursion that cannot loop: bounded by a checked depth, or never following an alias.
 */
final class BoundedOrNotFollowing
{
    private const int MAX_DEPTH = 100;

    public static function count(Node $node, int $depth = 0): int
    {
        if ($depth > self::MAX_DEPTH) {
            return 0;
        }

        $node  = NodeOps::deref($node);
        $total = 1;
        foreach ($node->content as $child) {
            $total += self::count($child, $depth + 1);
        }

        return $total;
    }

    /**
     * Reads the alias target but only walks the children, which are a tree.
     */
    public function repoint(Node $node): void
    {
        if ($node->aliasTarget instanceof Node) {
            $node->aliasTarget = null;
        }

        foreach ($node->content as $child) {
            $this->repoint($child);
        }
    }

    /**
     * Follows an alias once, in a method that does not recurse.
     */
    public function resolvedValue(Node $node): string
    {
        return NodeOps::deref($node)->value;
    }

    /**
     * Follows aliases in $source but descends the caller's own tree $target in step with it, so the recursion
     * is as deep as the finite tree and no deeper.
     */
    public function mergeInto(Node $target, Node $source): void
    {
        $source = NodeOps::deref($source);
        foreach ($source->content as $index => $child) {
            if (isset($target->content[$index])) {
                $this->mergeInto($target->content[$index], $child);
            }
        }
    }

    /**
     * The guard sits in one method of the cycle and the other passes the depth along.
     */
    public function guardedHere(Node $node, int $depth): int
    {
        if ($depth > self::MAX_DEPTH) {
            return 0;
        }

        return $this->guardedThere(NodeOps::deref($node), $depth + 1);
    }

    public function guardedThere(Node $node, int $depth): int
    {
        $total = 0;
        foreach ($node->content as $child) {
            $total += $this->guardedHere(NodeOps::deref($child), $depth);
        }

        return $total;
    }

    public function leaves(Node $node): int
    {
        $total = 0;
        foreach ($node->content as $child) {
            $total += $this->leaves($child);
        }

        return $total;
    }
}
