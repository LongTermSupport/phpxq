<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\AliasRecursion;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Unbounded alias recursion written with the less common call and fetch syntax.
 */
final class OddSyntaxUnbounded
{
    public function viaNullsafe(?Node $node): int
    {
        $target = $node?->aliasTarget ?? $node;
        $total  = 0;
        foreach ($target?->content ?? [] as $child) {
            $total += $this->viaNullsafe($child);
        }

        return $total;
    }

    public function namedArgument(Node $node): int
    {
        $node  = NodeOps::deref($node);
        $total = 0;
        foreach ($node->content as $child) {
            $total += $this->namedArgument(node: $child);
        }

        return $total;
    }

    public function spreadArgument(Node $node): int
    {
        $node  = NodeOps::deref($node);
        $total = 0;
        foreach ($node->content as $child) {
            $total += $this->spreadArgument(...[$child]);
        }

        return $total;
    }

    public static function staticCall(Node $node): int
    {
        $node  = NodeOps::deref($node);
        $total = 0;
        foreach ($node->content as $child) {
            $total += static::staticCall($child);
        }

        return $total;
    }
}
