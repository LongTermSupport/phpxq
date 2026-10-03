<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\Codec\DecimalLiteral;

/**
 * Type tests and jq's total ordering over the value model.
 *
 * Value model: null, bool, int|float|PreciseNumber, string, list (a PHP list array), JsonObject.
 *
 * @api
 */
final class Values
{
    private const array TYPE_ORDER = [
        'null'    => 0,
        'boolean' => 1,
        'number'  => 2,
        'string'  => 3,
        'array'   => 4,
        'object'  => 5,
    ];

    private function __construct()
    {
    }

    /**
     * The jq type name as returned by the `type` builtin.
     */
    public static function typeName(mixed $value): string
    {
        return match (true) {
            null === $value                                                     => 'null',
            \is_bool($value)                                                    => 'boolean',
            \is_int($value), \is_float($value), $value instanceof PreciseNumber => 'number',
            \is_string($value)                                                  => 'string',
            \is_array($value)                                                   => 'array',
            $value instanceof JsonObject                                        => 'object',
            default                                                             => throw new InvalidArgumentException('Not a JSON value: ' . get_debug_type($value)),
        };
    }

    /**
     * jq truthiness: only null and false are falsy.
     */
    public static function isTruthy(mixed $value): bool
    {
        return null !== $value && false !== $value;
    }

    public static function toFloat(int|float|PreciseNumber $number): float
    {
        return $number instanceof PreciseNumber ? $number->value : (float)$number;
    }

    /**
     * jq ordering: null < false < true < numbers < strings < arrays < objects. Returns -1, 0 or 1.
     */
    public static function compare(mixed $left, mixed $right): int
    {
        $leftType  = self::typeName($left);
        $rightType = self::typeName($right);
        if ($leftType !== $rightType) {
            return self::TYPE_ORDER[$leftType] <=> self::TYPE_ORDER[$rightType];
        }

        if ($left instanceof JsonObject && $right instanceof JsonObject) {
            return self::compareObjects($left, $right);
        }

        if (\is_array($left) && \is_array($right)) {
            return self::compareLists($left, $right);
        }

        if ($left instanceof PreciseNumber && $right instanceof PreciseNumber) {
            return DecimalLiteral::compare($left->literal, $right->literal);
        }

        if (self::isNumber($left) && self::isNumber($right)) {
            return self::compareNumbers(self::toFloat($left), self::toFloat($right));
        }

        if (\is_string($left) && \is_string($right)) {
            return strcmp($left, $right) <=> 0;
        }

        if (\is_bool($left) && \is_bool($right)) {
            return (int)$left <=> (int)$right;
        }

        return 0;
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        return 0 === self::compare($left, $right);
    }

    /**
     * @phpstan-assert-if-true int|float|PreciseNumber $value
     */
    private static function isNumber(mixed $value): bool
    {
        return \is_int($value) || \is_float($value) || $value instanceof PreciseNumber;
    }

    private static function compareNumbers(float $left, float $right): int
    {
        // jq sorts nan below every number, including itself
        if (is_nan($left)) {
            return -1;
        }

        if (is_nan($right)) {
            return 1;
        }

        return $left <=> $right;
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    private static function compareLists(array $left, array $right): int
    {
        $shared = min(\count($left), \count($right));
        for ($i = 0; $i < $shared; ++$i) {
            $result = self::compare($left[$i], $right[$i]);
            if (0 !== $result) {
                return $result;
            }
        }

        return \count($left) <=> \count($right);
    }

    private static function compareObjects(JsonObject $left, JsonObject $right): int
    {
        $leftKeys  = $left->sortedKeys();
        $rightKeys = $right->sortedKeys();
        $result    = self::compareLists($leftKeys, $rightKeys);
        if (0 !== $result) {
            return $result;
        }

        foreach ($leftKeys as $key) {
            $result = self::compare($left->get($key), $right->get($key));
            if (0 !== $result) {
                return $result;
            }
        }

        return 0;
    }
}
