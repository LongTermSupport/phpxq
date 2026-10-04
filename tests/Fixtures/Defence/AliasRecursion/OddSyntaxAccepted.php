<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\AliasRecursion;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * Call and fetch syntax the rule cannot follow, or that never reaches an alias; none of it may crash the rule.
 */
abstract class OddSyntaxAccepted
{
    abstract public function nothing(Node $node): int;

    /**
     * @param list<Node> $nodes
     *
     * @return list<mixed>
     */
    public function firstClassCallable(array $nodes): array
    {
        $node = NodeOps::deref($nodes[0]);

        return array_map($this->firstClassCallable(...), $node->content);
    }

    public function dynamicNamesAndClasses(Node $node, string $name, string $class): mixed
    {
        $node      = NodeOps::deref($node);
        $result    = $this->$name($node);
        $static    = $class::run($node);
        $object    = new $class($node);
        $anonymous = new class($node) {
            public function __construct(public Node $node)
            {
            }

            public function again(): int
            {
                return $this->again();
            }
        };
        $closure = static fn (Node $inner): ?Node => $inner?->content[0] ?? null;

        return [$result, $static, $object, $anonymous, $closure, $this->dynamicNamesAndClasses(...)];
    }

    public function nullsafeTreeWalk(?Node $node): int
    {
        $total = 0;
        foreach ($node?->content ?? [] as $child) {
            $total += $this->nullsafeTreeWalk($child);
        }

        return $total;
    }

    public function mergeNullsafe(Node $target, Node $source): void
    {
        $source = NodeOps::deref($source);
        foreach ($target->content as $index => $child) {
            $this->mergeNullsafe($target?->content[$index], $source);
        }
    }
}
