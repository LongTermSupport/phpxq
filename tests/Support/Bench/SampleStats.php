<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * Summary statistics of one workload's timing samples, in milliseconds.
 */
final readonly class SampleStats
{
    public function __construct(
        public int $count,
        public float $minMs,
        public float $medianMs,
        public float $meanMs,
        public float $p95Ms,
        public float $maxMs,
        public float $stddevMs,
    ) {
    }

    /**
     * Relative standard deviation as a percentage of the mean (the variance a reader should weigh).
     */
    public function coefficientOfVariationPercent(): float
    {
        if ($this->meanMs <= 0.0) {
            return 0.0;
        }

        return $this->stddevMs / $this->meanMs * 100.0;
    }

    /**
     * @return array{count: int, minMs: float, medianMs: float, meanMs: float, p95Ms: float, maxMs: float, stddevMs: float}
     */
    public function toArray(): array
    {
        return [
            'count'    => $this->count,
            'minMs'    => $this->minMs,
            'medianMs' => $this->medianMs,
            'meanMs'   => $this->meanMs,
            'p95Ms'    => $this->p95Ms,
            'maxMs'    => $this->maxMs,
            'stddevMs' => $this->stddevMs,
        ];
    }
}
