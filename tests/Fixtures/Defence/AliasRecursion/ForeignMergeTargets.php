<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\AliasRecursion;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * A merge-target helper in a class of its own, shaped like the one a traversal class once held: it resolves the
 * merge value and its items through aliases and returns what it found. `plainItems()` returns the items as they
 * are, without resolving anything.
 */
final readonly class ForeignMergeTargets
{
    /**
     * @return list<Node>
     */
    public static function targets(Node $value): array
    {
        $value = NodeOps::deref($value);
        if (NodeKindEnum::Mapping === $value->kind) {
            return [$value];
        }

        $targets = [];
        foreach ($value->content as $item) {
            $item = NodeOps::deref($item);
            if (NodeKindEnum::Mapping === $item->kind) {
                $targets[] = $item;
            }
        }

        return $targets;
    }

    /**
     * @return list<Node>
     */
    public static function plainItems(Node $value): array
    {
        return $value->content;
    }
}
