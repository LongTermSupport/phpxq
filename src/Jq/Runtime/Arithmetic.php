<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\Eval\ErrorText;
use LTS\PhpXq\Jq\Runtime\Eval\Text;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;

/**
 * jq's `+ - * / %` and unary minus over the value model, with jq's type rules and error messages
 * ("number (1) and string (\"a\") cannot be added"). Shared by the evaluator and by builtins such as
 * `add`. Numbers are computed as IEEE doubles and come back as int when integral and within 2^53.
 *
 * @api
 */
final readonly class Arithmetic
{
    private const int MAX_SAFE = 9007199254740992;

    private const int MAX_STRING = 2147483647;

    private const int MAX_MERGE_DEPTH = 10000;

    private function __construct()
    {
    }

    /**
     * An IEEE double as the value model's number: int when integral and |n| <= 2^53, else float.
     */
    public static function normalize(float $number): int|float
    {
        if (floor($number) === $number && abs($number) <= self::MAX_SAFE) {
            if (0.0 === $number && fdiv(1.0, $number) < 0) {
                return $number;
            }

            return (int)$number;
        }

        return $number;
    }

    /**
     * @throws JqException on a type error
     */
    public static function add(mixed $left, mixed $right): mixed
    {
        if (\is_int($left) && \is_int($right)) {
            $sum = $left + $right;

            return $sum > self::MAX_SAFE || $sum < -self::MAX_SAFE ? (float)$sum : $sum;
        }

        if (null === $left) {
            return $right;
        }

        if (null === $right) {
            return $left;
        }

        if (self::isNumber($left) && self::isNumber($right)) {
            return self::normalize(Values::toFloat($left) + Values::toFloat($right));
        }

        if (\is_string($left) && \is_string($right)) {
            return $left . $right;
        }

        if (\is_array($left) && \is_array($right)) {
            return [...$left, ...$right];
        }

        if ($left instanceof JsonObject && $right instanceof JsonObject) {
            $members = $left;
            foreach ($right->entries() as $key => $value) {
                $members = $members->with($key, $value);
            }

            return $members;
        }

        throw ErrorText::typeError2($left, $right, 'cannot be added');
    }

    /**
     * @throws JqException on a type error
     */
    public static function subtract(mixed $left, mixed $right): mixed
    {
        if (\is_int($left) && \is_int($right)) {
            $difference = $left - $right;

            return $difference > self::MAX_SAFE || $difference < -self::MAX_SAFE ? (float)$difference : $difference;
        }

        if (self::isNumber($left) && self::isNumber($right)) {
            return self::normalize(Values::toFloat($left) - Values::toFloat($right));
        }

        if (\is_array($left) && \is_array($right)) {
            $kept = [];
            foreach ($left as $element) {
                $removed = array_any($right, static fn ($candidate): bool => Values::equals($element, $candidate));
                if (!$removed) {
                    $kept[] = $element;
                }
            }

            return $kept;
        }

        throw ErrorText::typeError2($left, $right, 'cannot be subtracted');
    }

    /**
     * @throws JqException on a type error
     */
    public static function multiply(mixed $left, mixed $right): mixed
    {
        if (\is_int($left) && \is_int($right)) {
            $product = $left * $right;

            return $product <= self::MAX_SAFE && $product >= -self::MAX_SAFE ? $product : (float)$product;
        }

        if (self::isNumber($left) && self::isNumber($right)) {
            return self::normalize(Values::toFloat($left) * Values::toFloat($right));
        }

        if (\is_string($left) && self::isNumber($right)) {
            return self::repeat($left, Values::toFloat($right));
        }

        if (self::isNumber($left) && \is_string($right)) {
            return self::repeat($right, Values::toFloat($left));
        }

        if ($left instanceof JsonObject && $right instanceof JsonObject) {
            return self::mergeDeep($left, $right);
        }

        throw ErrorText::typeError2($left, $right, 'cannot be multiplied');
    }

    /**
     * @throws JqException on a type error or a zero divisor
     */
    public static function divide(mixed $left, mixed $right): mixed
    {
        if (self::isNumber($left) && self::isNumber($right)) {
            $divisor = Values::toFloat($right);
            if (0.0 === $divisor) {
                throw ErrorText::typeError2($left, $right, 'cannot be divided because the divisor is zero');
            }

            return self::normalize(Values::toFloat($left) / $divisor);
        }

        if (\is_string($left) && \is_string($right)) {
            return self::split($left, $right);
        }

        throw ErrorText::typeError2($left, $right, 'cannot be divided');
    }

    /**
     * @throws JqException on a type error or a zero divisor
     */
    public static function modulo(mixed $left, mixed $right): mixed
    {
        if (!self::isNumber($left) || !self::isNumber($right)) {
            throw ErrorText::typeError2($left, $right, 'cannot be divided');
        }

        $dividend = Values::toFloat($left);
        $divisor  = Values::toFloat($right);
        if (is_nan($dividend) || is_nan($divisor)) {
            return NAN;
        }

        $divisorInt = self::toInt($divisor);
        if (0 === $divisorInt) {
            throw ErrorText::typeError2($left, $right, 'cannot be divided (remainder) because the divisor is zero');
        }

        if (-1 === $divisorInt) {
            return 0;
        }

        $result = self::toInt($dividend) % $divisorInt;

        return $result > self::MAX_SAFE || $result < -self::MAX_SAFE ? (float)$result : $result;
    }

    /**
     * @throws JqException when the operand is not a number
     */
    public static function negate(mixed $value): mixed
    {
        if (\is_int($value)) {
            return 0 === $value ? -0.0 : -$value;
        }

        if (\is_float($value)) {
            return -$value;
        }

        if ($value instanceof PreciseNumber) {
            $literal = '-' === $value->literal[0] ? substr($value->literal, 1) : '-' . $value->literal;

            return new PreciseNumber(-$value->value, $literal);
        }

        throw ErrorText::typeError($value, 'cannot be negated');
    }

    /**
     * The jq string split used by `/` and `split/1`: an empty separator splits into codepoints.
     *
     * @return list<string>
     */
    public static function split(string $text, string $separator): array
    {
        if ('' === $text) {
            return [];
        }

        if ('' === $separator) {
            return Text::chars($text);
        }

        return explode($separator, $text);
    }

    /**
     * @phpstan-assert-if-true int|float|PreciseNumber $value
     */
    private static function isNumber(mixed $value): bool
    {
        return \is_int($value) || \is_float($value) || $value instanceof PreciseNumber;
    }

    private static function toInt(float $number): int
    {
        if ($number >= 9.2233720368547758E18) {
            return \PHP_INT_MAX;
        }

        if ($number <= -9.2233720368547758E18) {
            return \PHP_INT_MIN;
        }

        return (int)$number;
    }

    private static function repeat(string $text, float $count): ?string
    {
        if (is_nan($count) || $count < 0) {
            return null;
        }

        if ('' === $text) {
            return '';
        }

        $times = $count >= 4294967296.0 ? 4294967296 : (int)$count;
        if (0 === $times) {
            return '';
        }

        if (\strlen($text) * $times > self::MAX_STRING) {
            throw new JqException('Repeat string result too long');
        }

        return str_repeat($text, $times);
    }

    private static function mergeDeep(JsonObject $left, JsonObject $right, int $depth = 0): JsonObject
    {
        if ($depth > self::MAX_MERGE_DEPTH) {
            throw new JqException('Object merge too deep');
        }

        $merged = $left;
        foreach ($right->entries() as $key => $value) {
            $existing = $merged->get($key);
            if ($value instanceof JsonObject && $existing instanceof JsonObject) {
                $merged = $merged->with($key, self::mergeDeep($existing, $value, $depth + 1));
            } else {
                $merged = $merged->with($key, $value);
            }
        }

        return $merged;
    }
}
