<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

use InvalidArgumentException;

final class StatsCalculator
{
    public function summarise(float ...$samplesMs): SampleStats
    {
        $count = \count($samplesMs);
        if (0 === $count) {
            throw new InvalidArgumentException('Cannot summarise zero samples');
        }

        $sorted = $samplesMs;
        sort($sorted);

        $mean     = array_sum($sorted) / $count;
        $variance = 0.0;
        foreach ($sorted as $sample) {
            $variance += ($sample - $mean) ** 2;
        }

        $stddev = $count > 1 ? sqrt($variance / ($count - 1)) : 0.0;

        return new SampleStats(
            count: $count,
            minMs: $sorted[0],
            medianMs: $this->percentile(50.0, ...$sorted),
            meanMs: $mean,
            p95Ms: $this->percentile(95.0, ...$sorted),
            maxMs: $sorted[$count - 1],
            stddevMs: $stddev,
        );
    }

    /**
     * Linear-interpolated percentile of an ascending list.
     */
    private function percentile(float $percent, float ...$sorted): float
    {
        $last = \count($sorted) - 1;
        $rank = $percent / 100.0 * $last;
        $low  = (int)floor($rank);
        $high = (int)ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }

        $fraction = $rank - $low;

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * $fraction;
    }
}
