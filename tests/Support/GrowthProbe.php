<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support;

use Closure;

/**
 * Measures how a workload's run time grows with its size, so a test can tell linear work from quadratic work
 * without depending on how fast the host is.
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
        $base   = self::fastest($workload, $baseSize);
        $scaled = self::fastest($workload, $baseSize * self::FACTOR);

        return $scaled / max($base, 1);
    }

    /**
     * @param Closure(int): mixed $workload
     *
     * @return int the fastest of RUNS runs, in nanoseconds
     */
    private static function fastest(Closure $workload, int $size): int
    {
        $best = \PHP_INT_MAX;
        for ($run = 0; $run < self::RUNS; ++$run) {
            $start = hrtime(true);
            $workload($size);
            $best = min($best, hrtime(true) - $start);
        }

        return $best;
    }
}
