<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

use InvalidArgumentException;
use RuntimeException;

/**
 * Writes deterministic benchmark inputs (JSON and YAML) into a directory. The same corpus name always
 * yields byte-identical files: no clock, no random seed, nothing committed.
 *
 * Corpora: `tiny` (a few bytes, startup cost), `small`/`medium`/`large` (arrays of records), `wide`
 * (one object with many keys) and `deep` (a long nesting chain).
 */
final class CorpusGenerator
{
    public const array CORPORA = ['tiny', 'small', 'medium', 'large', 'wide', 'deep'];

    private const array RECORD_COUNTS = ['small' => 100, 'medium' => 5_000, 'large' => 100_000];

    private const int WIDE_KEYS = 20_000;

    private const int DEEP_LEVELS = 100;

    private const array GROUPS = ['alpha', 'beta', 'gamma', 'delta', 'epsilon'];

    /**
     * @return array{json: string, yaml: string} paths of the written files
     */
    public function generate(string $directory, string $corpus): array
    {
        if (!\in_array($corpus, self::CORPORA, true)) {
            throw new InvalidArgumentException('Unknown corpus: ' . $corpus);
        }

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create corpus directory: ' . $directory);
        }

        $json = $directory . '/' . $corpus . '.json';
        $yaml = $directory . '/' . $corpus . '.yaml';
        $this->writeIfDifferent($json, $this->json($corpus));
        $this->writeIfDifferent($yaml, $this->yaml($corpus));

        return ['json' => $json, 'yaml' => $yaml];
    }

    public function json(string $corpus): string
    {
        return match ($corpus) {
            'tiny'  => "{\"a\":1,\"b\":[1,2,3],\"c\":\"x\"}\n",
            'wide'  => $this->encode($this->wide()) . "\n",
            'deep'  => $this->encode($this->deep()) . "\n",
            default => $this->encode($this->records($this->recordCount($corpus))) . "\n",
        };
    }

    public function yaml(string $corpus): string
    {
        return match ($corpus) {
            'tiny'  => "a: 1\nb:\n  - 1\n  - 2\n  - 3\nc: x\n",
            'wide'  => $this->yamlMap($this->wide(), 0),
            'deep'  => $this->yamlMap($this->deep(), 0),
            default => $this->yamlRecords(...$this->records($this->recordCount($corpus))),
        };
    }

    private function recordCount(string $corpus): int
    {
        return self::RECORD_COUNTS[$corpus] ?? throw new InvalidArgumentException('Not a record corpus: ' . $corpus);
    }

    /**
     * Pseudo-random but fully determined by the index (no PRNG state).
     *
     * @return list<array{id: int, name: string, group: string, active: bool, score: int, tags: list<string>, nested: array{x: int, y: int}}>
     */
    private function records(int $count): array
    {
        $records = [];
        for ($i = 0; $i < $count; ++$i) {
            $hash      = ($i * 2_654_435_761 + 12_345) % 4_294_967_296;
            $records[] = [
                'id'     => $i,
                'name'   => 'user-' . $i,
                'group'  => self::GROUPS[$hash % \count(self::GROUPS)],
                'active' => 0 === $hash % 3,
                'score'  => $hash       % 1000,
                'tags'   => ['t' . ($hash % 7), 't' . ($hash % 11)],
                'nested' => ['x' => $hash % 100, 'y' => ($hash >> 8) % 100],
            ];
        }

        return $records;
    }

    /**
     * @return array<string, int>
     */
    private function wide(): array
    {
        $map = [];
        for ($i = 0; $i < self::WIDE_KEYS; ++$i) {
            $map['key' . $i] = ($i * 7919) % 10_007;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function deep(): array
    {
        $node = ['leaf' => true, 'value' => 42];
        for ($level = self::DEEP_LEVELS; $level > 0; --$level) {
            $node = ['level' => $level, 'child' => $node];
        }

        return $node;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array{id: int, name: string, group: string, active: bool, score: int, tags: list<string>, nested: array{x: int, y: int}} ...$records
     */
    private function yamlRecords(array ...$records): string
    {
        $out = '';
        foreach ($records as $record) {
            $out .= \sprintf(
                "- id: %d\n  name: %s\n  group: %s\n  active: %s\n  score: %d\n  tags:\n    - %s\n    - %s\n  nested:\n    x: %d\n    y: %d\n",
                $record['id'],
                $record['name'],
                $record['group'],
                $record['active'] ? 'true' : 'false',
                $record['score'],
                $record['tags'][0],
                $record['tags'][1],
                $record['nested']['x'],
                $record['nested']['y'],
            );
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $map
     */
    private function yamlMap(array $map, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $out = '';
        foreach ($map as $key => $value) {
            if (\is_array($value)) {
                $out .= $pad . $key . ":\n" . $this->yamlMap($value, $indent + 1);

                continue;
            }

            $out .= $pad . $key . ': ' . $this->scalar($value) . "\n";
        }

        return $out;
    }

    private function scalar(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return \is_int($value) ? (string)$value : '';
    }

    private function writeIfDifferent(string $path, string $contents): void
    {
        if (is_file($path) && file_get_contents($path) === $contents) {
            return;
        }

        if (false === file_put_contents($path, $contents)) {
            throw new RuntimeException('Cannot write corpus file: ' . $path);
        }
    }
}
