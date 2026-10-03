<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Json\PreciseNumber;

/**
 * Number helpers shared by the builtins: arithmetic is IEEE double, and a result is a PHP int when it is
 * integral and within 2^53, so that ordinary numbers stay on the fast int path.
 *
 * @internal
 */
final class Num
{
    private const float TWO_TO_53 = 9007199254740992.0;

    private function __construct()
    {
    }

    /**
     * @phpstan-assert-if-true int|float|PreciseNumber $value
     */
    public static function isNumber(mixed $value): bool
    {
        return \is_int($value) || \is_float($value) || $value instanceof PreciseNumber;
    }

    public static function toFloat(int|float|PreciseNumber $number): float
    {
        return $number instanceof PreciseNumber ? $number->value : (float)$number;
    }

    /**
     * The value-model form of a double: an int when integral and within 2^53 (negative zero stays a float,
     * so it still prints as `-0`).
     */
    public static function of(int|float $value): int|float
    {
        if (\is_int($value)) {
            return $value;
        }

        if (is_finite($value) && abs($value) <= self::TWO_TO_53 && floor($value) === $value) {
            if (0.0 === $value && fdiv(1.0, $value) < 0) {
                return $value;
            }

            return (int)$value;
        }

        return $value;
    }

    /**
     * jq's integer conversion of a double (truncation toward zero, saturating, NaN is 0).
     */
    public static function toInt(float $value): int
    {
        if (is_nan($value)) {
            return 0;
        }

        if ($value >= 9.2233720368547758E18) {
            return \PHP_INT_MAX;
        }

        if ($value <= -9.2233720368547758E18) {
            return \PHP_INT_MIN;
        }

        return (int)$value;
    }
}
