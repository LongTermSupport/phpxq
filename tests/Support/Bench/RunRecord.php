<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * One complete benchmark run: where and how it ran, which targets took part and what each measured.
 */
final readonly class RunRecord
{
    public const int SCHEMA = 1;

    /**
     * @param array<string, string> $environment  machine, PHP and tool details (see EnvironmentProbe)
     * @param array<string, string> $settings     warm-up, repetitions and selection used
     * @param list<Target>          $targets
     * @param list<Measurement>     $measurements
     */
    public function __construct(
        public string $label,
        public string $startedAt,
        public array $environment,
        public array $settings,
        public array $targets,
        public array $measurements,
    ) {
    }

    public function find(string $targetId, string $workloadId): ?Measurement
    {
        foreach ($this->measurements as $measurement) {
            if ($measurement->targetId === $targetId && $measurement->workloadId === $workloadId) {
                return $measurement;
            }
        }

        return null;
    }

    public function target(string $id): ?Target
    {
        foreach ($this->targets as $target) {
            if ($target->id === $id) {
                return $target;
            }
        }

        return null;
    }
}
