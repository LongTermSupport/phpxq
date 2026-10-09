<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\UnguardedAliasRecursion;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;

/**
 * Recursion through own helpers that cannot loop: the helper never resolves an alias, only reads one without
 * returning what it found, or the cycle is bounded by a checked depth.
 */
final class HelpersThatDoNotResolve
{
    private const int MAX_DEPTH = 32;

    /**
     * @return list<Node>
     */
    public static function mappings(Node $node): array
    {
        $found = [];
        foreach (self::mappingChildren($node) as $child) {
            $found = [$child, ...self::mappings($child)];
        }

        return $found;
    }

    public function counted(Node $node): int
    {
        $total = $this->isAlias($node) ? 0 : 1;
        foreach ($node->content as $child) {
            $total += $this->counted($child);
        }

        return $total;
    }

    /**
     * @return list<Node>
     */
    public static function bounded(Node $mapping, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $found = [];
        foreach (self::resolved($mapping) as $source) {
            $found = [...$found, ...self::bounded($source, $depth + 1)];
        }

        return $found;
    }

    /**
     * @return list<Node>
     */
    private static function mappingChildren(Node $node): array
    {
        return array_values(array_filter($node->content, static fn (Node $child): bool => NodeKindEnum::Mapping === $child->kind));
    }

    private function isAlias(Node $node): bool
    {
        return $node->aliasTarget instanceof Node;
    }

    /**
     * @return list<Node>
     */
    private static function resolved(Node $mapping): array
    {
        return array_map(NodeTools::unwrap(...), $mapping->content);
    }
}
