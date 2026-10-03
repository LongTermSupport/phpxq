<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Json\Values;

/**
 * The six comparison operators over jq's total order, with fast paths for the common scalar pairs.
 *
 * @internal
 */
final class Cmp
{
    private function __construct()
    {
    }

    public static function eq(mixed $left, mixed $right): bool
    {
        if (\is_int($left) && \is_int($right)) {
            return $left === $right;
        }

        if (\is_string($left) && \is_string($right)) {
            return $left === $right;
        }

        return 0 === Values::compare($left, $right);
    }

    public static function ne(mixed $left, mixed $right): bool
    {
        return !self::eq($left, $right);
    }

    public static function lt(mixed $left, mixed $right): bool
    {
        if (\is_int($left) && \is_int($right)) {
            return $left < $right;
        }

        return Values::compare($left, $right) < 0;
    }

    public static function le(mixed $left, mixed $right): bool
    {
        if (\is_int($left) && \is_int($right)) {
            return $left <= $right;
        }

        return Values::compare($left, $right) <= 0;
    }

    public static function gt(mixed $left, mixed $right): bool
    {
        if (\is_int($left) && \is_int($right)) {
            return $left > $right;
        }

        return Values::compare($left, $right) > 0;
    }

    public static function ge(mixed $left, mixed $right): bool
    {
        if (\is_int($left) && \is_int($right)) {
            return $left >= $right;
        }

        return Values::compare($left, $right) >= 0;
    }
}
