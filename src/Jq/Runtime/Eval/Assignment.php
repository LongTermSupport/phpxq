<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathOps;

/**
 * Applying a computation to every path of an assignment's left-hand side: the shared core of `=`, `|=` and
 * the arithmetic update operators.
 *
 * @internal
 */
final class Assignment
{
    private function __construct()
    {
    }

    /**
     * Replace the value at every path by $valueFor(old value at that path).
     *
     * @param list<list<mixed>>      $paths
     * @param Closure(mixed): mixed $valueFor
     *
     * @throws JqException
     */
    public static function setAll(mixed $input, array $paths, Closure $valueFor): mixed
    {
        $result = $input;
        foreach ($paths as $path) {
            $result = PathOps::setPath($result, $path, $valueFor(PathOps::getPath($result, $path)));
        }

        return $result;
    }

    /**
     * `|=`: replace the value at every path by the first output of $update, and delete the paths for which
     * it yields no output.
     *
     * @param list<list<mixed>>       $paths
     * @param Closure(mixed): list<mixed> $update returns [] for no output, otherwise [first output]
     *
     * @throws JqException
     */
    public static function updateAll(mixed $input, array $paths, Closure $update): mixed
    {
        $result  = $input;
        $deleted = [];
        foreach ($paths as $path) {
            $outcome = $update(PathOps::getPath($result, $path));
            if ([] === $outcome) {
                $deleted[] = $path;

                continue;
            }

            $result = PathOps::setPath($result, $path, $outcome[0]);
        }

        return [] === $deleted ? $result : PathOps::deletePaths($result, $deleted);
    }

    /**
     * The paths of a path expression, evaluated in path mode against $input.
     *
     * @return list<list<mixed>>
     *
     * @throws JqException
     */
    public static function collect(Op $left, ?Env $env, mixed $input): array
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
}
