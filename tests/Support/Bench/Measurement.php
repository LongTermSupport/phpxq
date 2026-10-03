<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * What one target did on one workload: a status and, when it ran, its timing samples and their summary.
 */
final readonly class Measurement
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_NOT_IMPLEMENTED = 'not-implemented';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param list<float> $samplesMs
     */
    public function __construct(
        public string $targetId,
        public string $workloadId,
        public string $status,
        public int $outputBytes,
        public string $note,
        public array $samplesMs,
        public ?SampleStats $stats,
    ) {
    }

    /**
     * @return array{targetId: string, workloadId: string, status: string, outputBytes: int, note: string, samplesMs: list<float>, stats: array{count: int, minMs: float, medianMs: float, meanMs: float, p95Ms: float, maxMs: float, stddevMs: float}|null}
     */
    public function toArray(): array
    {
        return [
            'targetId'    => $this->targetId,
            'workloadId'  => $this->workloadId,
            'status'      => $this->status,
            'outputBytes' => $this->outputBytes,
            'note'        => $this->note,
            'samplesMs'   => $this->samplesMs,
            'stats'       => $this->stats?->toArray(),
        ];
    }
}
