<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;

/**
 * Read access to values: `.[k]`, `.[a:b]`, `.[]` with jq's exact semantics and error messages.
 *
 * @internal
 */
final class Access
{
    private const float INDEX_CLAMP = 4294967296.0;

    private function __construct()
    {
    }

    /**
     * @throws JqException
     */
    public static function index(mixed $target, mixed $key): mixed
    {
        if (\is_string($key)) {
            if ($target instanceof JsonObject) {
                return $target->get($key);
            }

            if (null === $target) {
                return null;
            }

            throw ErrorText::indexError($target, $key);
        }

        if (\is_int($key)) {
            if (\is_array($target)) {
                $count = \count($target);
                if ($key < 0) {
                    $key += $count;
                }

                return $key >= 0 && $key < $count ? $target[$key] : null;
            }

            if (null === $target) {
                return null;
            }

            throw ErrorText::indexError($target, $key);
        }

        if (\is_float($key) || $key instanceof PreciseNumber) {
            if (\is_array($target)) {
                $position = self::position($key);
                if (null === $position) {
                    return null;
                }

                $count = \count($target);
                if ($position < 0) {
                    $position += $count;
                }

                return $position >= 0 && $position < $count ? $target[$position] : null;
            }

            if (null === $target) {
                return null;
            }

            throw ErrorText::indexError($target, $key);
        }

        if ($key instanceof JsonObject) {
            if (null === $target) {
                return null;
            }

            if (\is_array($target) || \is_string($target)) {
                return self::slice($target, $key->get('start'), $key->get('end'));
            }

            throw ErrorText::indexError($target, $key);
        }

        if (\is_array($key) && \is_array($target)) {
            return self::indices($target, $key);
        }

        if (null === $key && null === $target) {
            return null;
        }

        throw ErrorText::indexError($target, $key);
    }

    /**
     * @throws JqException
     */
    public static function slice(mixed $target, mixed $from, mixed $to): mixed
    {
        if (null === $target) {
            return null;
        }

        if (\is_array($target)) {
            [$start, $end] = self::bounds(\count($target), $from, $to);

            return \array_slice($target, $start, $end - $start);
        }

        if (\is_string($target)) {
            [$start, $end] = self::bounds(Text::length($target), $from, $to);

            return Text::slice($target, $start, $end);
        }

        throw ErrorText::indexError($target, new JsonObject(['start' => $from, 'end' => $to]));
    }

    /**
     * Clamp slice bounds like jq: start is floored, end is ceiled, negative counts from the end, null is the
     * open end, nan falls back to the open end.
     *
     * @return array{int, int}
     *
     * @throws JqException
     */
    public static function bounds(int $length, mixed $from, mixed $to): array
    {
        $fromNumber = null === $from || \is_int($from) || \is_float($from) || $from instanceof PreciseNumber;
        $toNumber   = null === $to   || \is_int($to) || \is_float($to) || $to instanceof PreciseNumber;
        if (!$fromNumber || !$toNumber) {
            throw new JqException('Start and end indices of an array slice must be numbers');
        }

        $start = null === $from ? 0.0 : Values::toFloat($from);
        $end   = null === $to ? (float)$length : Values::toFloat($to);
        if (is_nan($start)) {
            $start = 0.0;
        }

        if (is_nan($end)) {
            $end = (float)$length;
        }

        if ($start < 0) {
            $start += $length;
        }

        if ($end < 0) {
            $end += $length;
        }

        $startIndex = (int)floor(max(0.0, min((float)$length, $start)));
        $endIndex   = (int)ceil(max(0.0, min((float)$length, $end)));

        return [$startIndex, max($startIndex, $endIndex)];
    }

    /**
     * The positions of the subarray $needle inside $haystack (`.[[1,2]]`).
     *
     * @param array<array-key, mixed> $haystack
     * @param array<array-key, mixed> $needle
     *
     * @return ?list<int>
     */
    public static function indices(array $haystack, array $needle): ?array
    {
        $needleCount = \count($needle);
        if (0 === $needleCount) {
            return null;
        }

        $found = [];
        $last  = \count($haystack) - $needleCount;
        for ($i = 0; $i <= $last; ++$i) {
            $matches = true;
            for ($j = 0; $j < $needleCount; ++$j) {
                if (!Values::equals($haystack[$i + $j], $needle[$j])) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                $found[] = $i;
            }
        }

        return $found;
    }

    /**
     * `.[]`: call $each with every element (array) or member value (object).
     *
     * @param Closure(mixed): void $each
     *
     * @throws JqException
     */
    public static function each(mixed $target, Closure $each): void
    {
        if (\is_array($target)) {
            foreach ($target as $value) {
                $each($value);
            }

            return;
        }

        if ($target instanceof JsonObject) {
            foreach ($target->values() as $value) {
                $each($value);
            }

            return;
        }

        throw ErrorText::iterateError($target);
    }

    /**
     * The keys of `.[]` for path tracking: ints for arrays, strings for objects.
     *
     * @return list<int|string>
     *
     * @throws JqException
     */
    public static function keys(mixed $target): array
    {
        if (\is_array($target)) {
            return \count($target) > 0 ? range(0, \count($target) - 1) : [];
        }

        if ($target instanceof JsonObject) {
            return $target->keys();
        }

        throw ErrorText::iterateError($target);
    }

    /**
     * The array position of a numeric key: floored, null for nan, clamped to a sane range.
     */
    public static function position(float|PreciseNumber $key): ?int
    {
        $number = $key instanceof PreciseNumber ? $key->value : $key;
        if (is_nan($number)) {
            return null;
        }

        return (int)floor(max(-self::INDEX_CLAMP, min(self::INDEX_CLAMP, $number)));
    }
}
