<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\AliasRecursion;

use LTS\PhpXq\Yaml\MergeSources;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;

/**
 * Each recursive method follows an alias through a helper rather than directly: an own method whose return
 * value comes from a resolver (possibly through further own methods), or the shared merge-key resolver.
 */
final class ResolvesThroughHelpers
{
    /**
     * The shape of the merge-key expansion: the merge sources come out of an own helper that resolves them.
     *
     * @return list<Node>
     */
    public static function pairs(Node $mapping): array
    {
        $pairs = [];
        foreach ($mapping->content as $value) {
            foreach (self::sources($value) as $source) {
                $pairs = [...$pairs, ...self::pairs($source)];
            }
        }

        return $pairs;
    }

    public function transitive(Node $node): int
    {
        $total = 1;
        foreach ($this->outer($node)->content as $child) {
            $total += $this->transitive($child);
        }

        return $total;
    }

    public static function viaMergeSources(Node $mapping): int
    {
        $total = 1;
        foreach (MergeSources::of($mapping) as $target) {
            $total += self::viaMergeSources($target);
        }

        return $total;
    }

    /**
     * @return list<Node>
     */
    private static function sources(Node $value): array
    {
        $sources = [];
        foreach ($value->content as $item) {
            $item = NodeTools::unwrap($item);
            if (NodeKindEnum::Mapping === $item->kind) {
                $sources[] = $item;
            }
        }

        return $sources;
    }

    private function outer(Node $node): Node
    {
        return $this->inner($node);
    }

    private function inner(Node $node): Node
    {
        return $node->aliasTarget ?? $node;
    }
}
