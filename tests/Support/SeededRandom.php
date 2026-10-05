<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support;

/**
 * A deterministic pseudo-random sequence (xorshift64) for generated test inputs: the same seed always
 * yields the same values, so a failing generated case reproduces exactly. Not for anything security related.
 */
final class SeededRandom
{
    private const int MAX_UINT32 = 0xFFFFFFFF;

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = 0 === $seed ? 0x1E3779B97F4A7C15 : $seed;
    }

    /**
     * An integer in the inclusive range [$min, $max], where the range spans at most 2^32 values.
     */
    public function between(int $min, int $max): int
    {
        $span = $max - $min + 1;

        return $min + ($this->next() % $span);
    }

    /**
     * One element of a non-empty list.
     *
     * @template T
     *
     * @param T ...$items
     *
     * @return T
     */
    public function pick(mixed ...$items): mixed
    {
        return $items[$this->between(0, \count($items) - 1)];
    }

    private function next(): int
    {
        $x = $this->state;
        $x ^= $x << 13;
        $x ^= ($x >> 7) & (\PHP_INT_MAX >> 6);
        $x ^= $x << 17;
        $this->state = $x;

        return ($x >> 32) & self::MAX_UINT32;
    }
}
