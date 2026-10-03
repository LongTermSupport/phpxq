<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * The paths of an assignment grouped by their first key, recursively. It only exists for path sets that can
 * be applied in one pass: every key is a string or a non-negative int, no path is a prefix of (or equal to)
 * another, and no level mixes string and int keys. Anything else is left to the sequential algorithm.
 *
 * @internal
 */
final class PathTrie
{
    /** @var array<string, array{int|string, int|PathTrie}> key, then leaf index or sub-trie, by normalised key */
    public array $children = [];

    private bool $ints = false;

    private bool $strings = false;

    /**
     * @param list<list<mixed>> $paths
     */
    public static function build(array $paths): ?self
    {
        $root = new self();
        foreach ($paths as $index => $path) {
            if ([] === $path) {
                return null;
            }

            $node = $root;
            $last = \count($path) - 1;
            foreach ($path as $position => $key) {
                if (\is_string($key)) {
                    $normal        = 's' . $key;
                    $node->strings = true;
                } elseif (\is_int($key) && $key >= 0) {
                    $normal     = 'i' . $key;
                    $node->ints = true;
                } else {
                    return null;
                }

                if ($node->ints && $node->strings) {
                    return null;
                }

                $existing = $node->children[$normal] ?? null;
                if ($position === $last) {
                    if (null !== $existing) {
                        return null;
                    }

                    $node->children[$normal] = [$key, $index];

                    continue;
                }

                if (null === $existing) {
                    $child                   = new self();
                    $node->children[$normal] = [$key, $child];
                    $node                    = $child;
                } elseif ($existing[1] instanceof self) {
                    $node = $existing[1];
                } else {
                    return null;
                }
            }
        }

        return $root;
    }

    public function hasIntKeys(): bool
    {
        return $this->ints;
    }
}
