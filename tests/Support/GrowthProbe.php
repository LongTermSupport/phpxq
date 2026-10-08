<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support;

use Closure;
use RuntimeException;

/**
 * Measures how a workload's CPU time grows with its size, so a test can tell linear work from quadratic work
 * without depending on how fast or how busy the host is.
 *
 * The workload runs at a base size and at FACTOR times that size, best of RUNS each. Linear work grows by about
 * FACTOR, quadratic work by about FACTOR squared; LINEAR_CEILING sits between the two.
 */
final class GrowthProbe
{
    /**
     * How many times larger the second run is than the first.
     */
    public const int FACTOR = 4;

    /**
     * Twice the linear growth and well under the quadratic growth of FACTOR squared.
     */
    public const float LINEAR_CEILING = 8.0;

    /**
     * Runs per size; the fastest counts, which filters out scheduling noise.
     */
    private const int RUNS = 3;

    /**
     * The shortest a base-size sample may be, in nanoseconds: a faster workload is repeated within each sample
     * until it takes this long, so timer resolution and scheduling jitter stay small against it.
     */
    private const int MIN_SAMPLE_NANOSECONDS = 30_000_000;

    private function __construct()
    {
    }

    /**
     * @param Closure(int): mixed $workload runs the work at the given size
     *
     * @return float the run time at FACTOR times the base size divided by the run time at the base size
     */
    public static function growth(Closure $workload, int $baseSize): float
    {
        $repeats = 1;
        $base    = self::fastest($workload, $baseSize, $repeats);
        while ($base < self::MIN_SAMPLE_NANOSECONDS) {
            $repeats *= 2;
            $base = self::fastest($workload, $baseSize, $repeats);
        }

        $scaled = self::fastest($workload, $baseSize * self::FACTOR, $repeats);

        return $scaled / $base;
    }

    /**
     * @param Closure(int): mixed $workload
     *
     * @return int the fastest of RUNS samples of $repeats runs each, in CPU nanoseconds
     */
    private static function fastest(Closure $workload, int $size, int $repeats): int
    {
        $best = \PHP_INT_MAX;
        for ($run = 0; $run < self::RUNS; ++$run) {
            $start = self::cpuNanoseconds();
            for ($repeat = 0; $repeat < $repeats; ++$repeat) {
                $workload($size);
            }

            $best = min($best, self::cpuNanoseconds() - $start);
        }

        return max($best, 1);
    }

    /**
     * CPU time this process has used, user plus system: unlike wall time it does not grow while other
     * processes hold the CPU, so a busy host does not distort the ratio.
     */
    private static function cpuNanoseconds(): int
    {
        $usage = getrusage();
        if (false === $usage) {
            throw new RuntimeException('getrusage() is unavailable, so CPU time cannot be measured');
        }

        return (self::field($usage, 'ru_utime.tv_sec') + self::field($usage, 'ru_stime.tv_sec')) * 1_000_000_000
            + (self::field($usage, 'ru_utime.tv_usec') + self::field($usage, 'ru_stime.tv_usec')) * 1_000;
    }

    /**
     * @param array<array-key, mixed> $usage
     */
    private static function field(array $usage, string $name): int
    {
        $value = $usage[$name] ?? null;
        if (!\is_int($value)) {
            throw new RuntimeException('getrusage() gave no integer ' . $name);
        }

        return $value;
    }
}
