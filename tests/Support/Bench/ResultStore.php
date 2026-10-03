<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Persists a RunRecord as pretty-printed JSON (the machine-readable result format) and reads it back.
 */
final class ResultStore
{
    public function save(string $path, RunRecord $record): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create directory: ' . $directory);
        }

        $document = [
            'schema'       => RunRecord::SCHEMA,
            'label'        => $record->label,
            'startedAt'    => $record->startedAt,
            'environment'  => $record->environment,
            'settings'     => $record->settings,
            'targets'      => array_map(static fn (Target $target): array => $target->toArray(), $record->targets),
            'measurements' => array_map(static fn (Measurement $m): array => $m->toArray(), $record->measurements),
        ];

        $json = json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === file_put_contents($path, $json . "\n")) {
            throw new RuntimeException('Cannot write result file: ' . $path);
        }
    }

    public function load(string $path): RunRecord
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (false === $json) {
            throw new InvalidArgumentException('Cannot read result file: ' . $path);
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException('Invalid JSON in ' . $path . ': ' . $jsonException->getMessage(), 0, $jsonException);
        }

        $root = $this->map($data, 'document');
        if (RunRecord::SCHEMA !== ($root['schema'] ?? null)) {
            throw new InvalidArgumentException('Unsupported result schema in ' . $path);
        }

        return new RunRecord(
            label: $this->string($root['label'] ?? null, 'label'),
            startedAt: $this->string($root['startedAt'] ?? null, 'startedAt'),
            environment: $this->stringMap($root['environment'] ?? null, 'environment'),
            settings: $this->stringMap($root['settings'] ?? null, 'settings'),
            targets: array_map($this->target(...), $this->list($root['targets'] ?? null, 'targets')),
            measurements: array_map($this->measurement(...), $this->list($root['measurements'] ?? null, 'measurements')),
        );
    }

    private function target(mixed $data): Target
    {
        $row = $this->map($data, 'target');

        return new Target(
            $this->string($row['id'] ?? null, 'target.id'),
            $this->string($row['tool'] ?? null, 'target.tool'),
            $this->string($row['role'] ?? null, 'target.role'),
            $this->string($row['version'] ?? null, 'target.version'),
            $this->string($row['command'] ?? null, 'target.command'),
        );
    }

    private function measurement(mixed $data): Measurement
    {
        $row     = $this->map($data, 'measurement');
        $samples = array_map(
            fn (mixed $sample): float => $this->number($sample, 'sample'),
            $this->list($row['samplesMs'] ?? null, 'samplesMs'),
        );

        return new Measurement(
            targetId: $this->string($row['targetId'] ?? null, 'targetId'),
            workloadId: $this->string($row['workloadId'] ?? null, 'workloadId'),
            status: $this->string($row['status'] ?? null, 'status'),
            outputBytes: (int)$this->number($row['outputBytes'] ?? null, 'outputBytes'),
            note: $this->string($row['note'] ?? null, 'note'),
            samplesMs: $samples,
            stats: null === ($row['stats'] ?? null) ? null : $this->stats($row['stats']),
        );
    }

    private function stats(mixed $data): SampleStats
    {
        $row = $this->map($data, 'stats');

        return new SampleStats(
            (int)$this->number($row['count'] ?? null, 'count'),
            $this->number($row['minMs'] ?? null, 'minMs'),
            $this->number($row['medianMs'] ?? null, 'medianMs'),
            $this->number($row['meanMs'] ?? null, 'meanMs'),
            $this->number($row['p95Ms'] ?? null, 'p95Ms'),
            $this->number($row['maxMs'] ?? null, 'maxMs'),
            $this->number($row['stddevMs'] ?? null, 'stddevMs'),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function map(mixed $value, string $what): array
    {
        return \is_array($value) ? $value : throw new InvalidArgumentException('Expected an object for ' . $what);
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $what): array
    {
        return \is_array($value) ? array_values($value) : throw new InvalidArgumentException('Expected a list for ' . $what);
    }

    private function string(mixed $value, string $what): string
    {
        return \is_string($value) ? $value : throw new InvalidArgumentException('Expected a string for ' . $what);
    }

    private function number(mixed $value, string $what): float
    {
        return \is_int($value) || \is_float($value) ? (float)$value : throw new InvalidArgumentException('Expected a number for ' . $what);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value, string $what): array
    {
        $out = [];
        foreach ($this->map($value, $what) as $key => $item) {
            $out[(string)$key] = $this->string($item, $what . '.' . $key);
        }

        return $out;
    }
}
