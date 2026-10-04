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
final readonly class RecurseOp implements OpInterface
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

        self::walkPaths($input, $emit, ...$path);
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function walkPaths(mixed $value, Closure $emit, mixed ...$path): void
    {
        $path = array_values($path);
        $emit($path, $value);
        if (\is_array($value)) {
            foreach ($value as $index => $child) {
                self::walkPaths($child, $emit, ...[...$path, $index]);
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->entries() as $key => $child) {
                self::walkPaths($child, $emit, ...[...$path, $key]);
            }
        }
    }
}
