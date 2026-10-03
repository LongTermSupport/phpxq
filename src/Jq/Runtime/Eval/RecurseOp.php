<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Json\JsonObject;

/**
 * `recurse` / `..`: the input and, depth first, every value nested in it. Valid in path expressions.
 *
 * @internal
 */
final class RecurseOp implements Op
{
    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        self::walk($input, $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        if (null === $path) {
            $emit(null, $input);
            if (\is_array($input) || $input instanceof JsonObject) {
                throw PathErrors::iterate($input);
            }

            return;
        }

        self::walkPaths($path, $input, $emit);
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function walk(mixed $value, Closure $emit): void
    {
        $emit($value);
        if (\is_array($value)) {
            foreach ($value as $child) {
                self::walk($child, $emit);
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->values() as $child) {
                self::walk($child, $emit);
            }
        }
    }

    /**
     * @param list<mixed>                        $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function walkPaths(array $path, mixed $value, Closure $emit): void
    {
        $emit($path, $value);
        if (\is_array($value)) {
            foreach ($value as $index => $child) {
                $childPath   = $path;
                $childPath[] = $index;
                self::walkPaths($childPath, $child, $emit);
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->entries() as $key => $child) {
                $childPath   = $path;
                $childPath[] = $key;
                self::walkPaths($childPath, $child, $emit);
            }
        }
    }
}
