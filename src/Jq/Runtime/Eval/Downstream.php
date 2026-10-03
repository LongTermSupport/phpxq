<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * Tells errors raised by an expression apart from errors raised by whatever consumes its output. An
 * expression like `try` or `//` runs its body with a continuation wrapped by {@see self::guard()}; while that
 * continuation runs {@see self::active()} is true, so an exception that escapes the body at that moment
 * belongs to the consumer and must pass through untouched.
 *
 * @internal
 */
final class Downstream
{
    private bool $active = false;

    /**
     * @param Closure(mixed): void $emit
     *
     * @return Closure(mixed): void
     */
    public function guard(Closure $emit): Closure
    {
        return function (mixed $value) use ($emit): void {
            $this->active = true;
            $emit($value);
            $this->active = false;
        };
    }

    /**
     * @param Closure(?list<mixed>, mixed): void $emit
     *
     * @return Closure(?list<mixed>, mixed): void
     */
    public function guardPaths(Closure $emit): Closure
    {
        return function (?array $path, mixed $value) use ($emit): void {
            $this->active = true;
            $emit(null === $path ? null : array_values($path), $value);
            $this->active = false;
        };
    }

    public function active(): bool
    {
        return $this->active;
    }
}
