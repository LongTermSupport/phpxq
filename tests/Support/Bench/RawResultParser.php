<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

use InvalidArgumentException;

/**
 * Reads the tab-separated files the bash runner writes.
 *
 * Targets file: id, tool, role, version, command.
 * Measurements file: target id, workload id, status, output bytes, comma-separated samples (ms), note.
 */
final readonly class RawResultParser
{
    private const array STATUSES = [
        Measurement::STATUS_OK,
        Measurement::STATUS_FAILED,
        Measurement::STATUS_NOT_IMPLEMENTED,
        Measurement::STATUS_UNAVAILABLE,
    ];

    public function __construct(private StatsCalculator $calculator)
    {
    }

    /**
     * @return list<Target>
     */
    public function targets(string $tsv): array
    {
        $targets = [];
        foreach ($this->rows($tsv, 5) as $row) {
            $targets[] = new Target($row[0], $row[1], $row[2], $row[3], $row[4]);
        }

        return $targets;
    }

    /**
     * @return list<Measurement>
     */
    public function measurements(string $tsv): array
    {
        $measurements = [];
        foreach ($this->rows($tsv, 6) as $row) {
            if (!\in_array($row[2], self::STATUSES, true)) {
                throw new InvalidArgumentException('Unknown measurement status: ' . $row[2]);
            }

            $samples = '' === $row[4] ? [] : array_map(floatval(...), explode(',', $row[4]));

            $measurements[] = new Measurement(
                targetId: $row[0],
                workloadId: $row[1],
                status: $row[2],
                outputBytes: (int)$row[3],
                note: $row[5],
                samplesMs: $samples,
                stats: [] === $samples ? null : $this->calculator->summarise($samples),
            );
        }

        return $measurements;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $tsv, int $columns): array
    {
        $rows = [];
        foreach (explode("\n", $tsv) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $fields = explode("\t", $line);
            if (\count($fields) !== $columns) {
                throw new InvalidArgumentException(\sprintf('Expected %d tab-separated fields, got %d: %s', $columns, \count($fields), $line));
            }

            $rows[] = $fields;
        }

        return $rows;
    }
}
