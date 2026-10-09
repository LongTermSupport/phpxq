<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\AliasRecursion;

use LTS\PhpXq\Yaml\Node;

/**
 * The shape of the old spec-fixed `explode` merge expansion: the recursion follows merge targets that a helper of
 * another class resolved through aliases. `fixedPairs()` must be flagged; `plainPairs()`, whose helper resolves
 * nothing, and `boundedPairs()`, which checks a depth, must not.
 */
final readonly class ResolvesThroughAnotherClass
{
    private const int MAX_DEPTH = 32;

    /**
     * @return list<Node>
     */
    public static function fixedPairs(Node $map): array
    {
        $flat = [];
        foreach ($map->content as $value) {
            foreach (ForeignMergeTargets::targets($value) as $target) {
                $flat = [...$flat, ...self::fixedPairs($target)];
            }
        }

        return $flat;
    }

    /**
     * @return list<Node>
     */
    public static function plainPairs(Node $map): array
    {
        $flat = [];
        foreach ($map->content as $value) {
            foreach (ForeignMergeTargets::plainItems($value) as $item) {
                $flat = [...$flat, ...self::plainPairs($item)];
            }
        }

        return $flat;
    }

    /**
     * @return list<Node>
     */
    public static function boundedPairs(Node $map, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $flat = [];
        foreach ($map->content as $value) {
            foreach (ForeignMergeTargets::targets($value) as $target) {
                $flat = [...$flat, ...self::boundedPairs($target, $depth + 1)];
            }
        }

        return $flat;
    }
}
