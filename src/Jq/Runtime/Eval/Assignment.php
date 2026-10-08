<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathOps;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Limits\AllocationLimit;

/**
 * Applying a computation to every path of an assignment's left-hand side: the shared core of `=`, `|=` and
 * the arithmetic update operators.
 *
 * jq defines these as a reduction over the paths with `setpath`, which is quadratic when every step copies
 * the container. When the paths are independent (see {@see PathTrie}) all new values are computed from the
 * original input and written in one pass; any other path set takes the sequential reduction.
 *
 * @internal
 */
final readonly class Assignment
{
    private function __construct()
    {
    }

    /**
     * Replace the value at every path by $valueFor(old value at that path).
     *
     * @param Closure(mixed): mixed $valueFor
     * @param list<mixed>           ...$paths
     *
     * @throws JqException
     */
    public static function setAll(mixed $input, Closure $valueFor, array ...$paths): mixed
    {
        $paths = array_values($paths);
        $trie  = \count($paths) > 1 ? PathTrie::build(...$paths) : null;
        if ($trie instanceof PathTrie) {
            $values = [];
            foreach ($paths as $index => $path) {
                $values[$index] = $valueFor(PathOps::getPath($input, ...$path));
            }

            return self::write($input, $trie, $values, []);
        }

        $result = $input;
        foreach ($paths as $path) {
            $result = PathOps::setPath($result, $valueFor(PathOps::getPath($result, ...$path)), ...$path);
        }

        return $result;
    }

    /**
     * `|=`: replace the value at every path by the first output of $update, and delete the paths for which
     * it yields no output.
     *
     * @param Closure(mixed): list<mixed> $update   returns [] for no output, otherwise [first output]
     * @param list<mixed>                 ...$paths
     *
     * @throws JqException
     */
    public static function updateAll(mixed $input, Closure $update, array ...$paths): mixed
    {
        $paths   = array_values($paths);
        $trie    = \count($paths) > 1 ? PathTrie::build(...$paths) : null;
        $deleted = [];
        if ($trie instanceof PathTrie) {
            $values  = [];
            $skipped = [];
            foreach ($paths as $index => $path) {
                $outcome = $update(PathOps::getPath($input, ...$path));
                if ([] === $outcome) {
                    $deleted[]       = $path;
                    $skipped[$index] = true;
                } else {
                    $values[$index] = $outcome[0];
                }
            }

            $result = self::write($input, $trie, $values, $skipped);

            return [] === $deleted ? $result : PathOps::deletePaths($result, ...$deleted);
        }

        $result = $input;
        foreach ($paths as $path) {
            $outcome = $update(PathOps::getPath($result, ...$path));
            if ([] === $outcome) {
                $deleted[] = $path;

                continue;
            }

            $result = PathOps::setPath($result, $outcome[0], ...$path);
        }

        return [] === $deleted ? $result : PathOps::deletePaths($result, ...$deleted);
    }

    /**
     * The paths of a path expression, evaluated in path mode against $input.
     *
     * @return list<list<mixed>>
     *
     * @throws JqException
     */
    public static function collect(OpInterface $left, ?Env $env, mixed $input): array
    {
        $paths = [];
        $left->paths($env, [], $input, static function (?array $path, mixed $found) use (&$paths): void {
            if (null === $path) {
                throw PathErrors::result($found);
            }

            $paths[] = $path;
        });

        return $paths;
    }

    /**
     * @param array<int, mixed> $values  new value per path index
     * @param array<int, true>  $skipped path indexes that receive no value
     *
     * @throws JqException
     */
    private static function write(mixed $target, PathTrie $trie, array $values, array $skipped): mixed
    {
        if ([] !== $skipped && !self::live($trie, $skipped)) {
            return $target;
        }

        if ($trie->hasIntKeys()) {
            return self::writeArray($target, $trie, $values, $skipped);
        }

        $members = [];
        if ($target instanceof JsonObject) {
            foreach ($target->entries() as $key => $member) {
                $members[$key] = $member;
            }
        }

        foreach ($trie->children as [$key, $child]) {
            $key = (string)$key;
            if ($child instanceof PathTrie) {
                $members[$key] = self::write($members[$key] ?? null, $child, $values, $skipped);
            } elseif (!isset($skipped[$child])) {
                $members[$key] = $values[$child];
            }
        }

        return new JsonObject($members);
    }

    /**
     * Whether any path below the trie node still receives a value.
     *
     * @param array<int, true> $skipped
     */
    private static function live(PathTrie $trie, array $skipped): bool
    {
        foreach ($trie->children as [, $child]) {
            if ($child instanceof PathTrie ? self::live($child, $skipped) : !isset($skipped[$child])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, true>  $skipped
     *
     * @throws JqException
     */
    private static function writeArray(mixed $target, PathTrie $trie, array $values, array $skipped): mixed
    {
        $elements = \is_array($target) ? $target : [];
        $highest  = -1;
        foreach ($trie->children as [$key]) {
            $highest = max($highest, (int)$key);
        }

        if ($highest > AllocationLimit::MAX_ARRAY_INDEX || AllocationLimit::padsTooFar($highest, \count($elements))) {
            throw new JqException(PathOps::INDEX_TOO_LARGE);
        }

        for ($i = \count($elements); $i <= $highest; ++$i) {
            $elements[] = null;
        }

        foreach ($trie->children as [$key, $child]) {
            $position = (int)$key;
            if ($child instanceof PathTrie) {
                $elements[$position] = self::write($elements[$position], $child, $values, $skipped);
            } elseif (!isset($skipped[$child])) {
                $elements[$position] = $values[$child];
            }
        }

        return $elements;
    }
}
