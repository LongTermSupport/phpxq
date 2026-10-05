<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\AliasRecursion;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Each method follows an alias and recurses into what it found, with nothing to stop a cycle.
 */
final class FollowsAliasesUnbounded
{
    public static function count(Node $node): int
    {
        $node  = NodeOps::deref($node);
        $total = 1;
        foreach ($node->content as $child) {
            $total += self::count($child);
        }

        return $total;
    }

    public function names(Node $node): string
    {
        $resolved = NodeTools::unwrap($node);
        $out      = '';
        foreach ($resolved->content as $child) {
            $out .= $this->names($child);
        }

        return $out;
    }

    public function viaTarget(Node $node): int
    {
        $target = $node->aliasTarget ?? $node;
        $total  = 0;
        foreach ($target->content as $child) {
            $total += $this->viaTarget($child);
        }

        return $total;
    }

    public function mutualA(Node $node): int
    {
        $node = NodeOps::deref($node);

        return 1 + $this->mutualB($node->content[0]);
    }

    public function mutualB(Node $node): int
    {
        return $this->mutualA($node);
    }

    public function depthIsNeverChecked(Node $node, int $depth): int
    {
        $node  = NodeOps::deref($node);
        $total = $depth;
        foreach ($node->content as $child) {
            $total += $this->depthIsNeverChecked($child, $depth + 1);
        }

        return $total;
    }
}
