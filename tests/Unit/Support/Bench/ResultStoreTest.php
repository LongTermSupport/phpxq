<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use InvalidArgumentException;
use LTS\PhpXq\Tests\Support\Bench\Measurement;
use LTS\PhpXq\Tests\Support\Bench\ResultStore;
use LTS\PhpXq\Tests\Support\Bench\RunRecord;
use LTS\PhpXq\Tests\Support\Bench\SampleStats;
use LTS\PhpXq\Tests\Support\Bench\Target;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ResultStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/phpxq-bench-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testRoundTripPreservesEverything(): void
    {
        $record = new RunRecord(
            label: 'baseline',
            startedAt: '2026-10-03T12:00:00+00:00',
            environment: ['php_version' => '8.5.0', 'cpu_model' => 'Test CPU'],
            settings: ['repetitions' => '5'],
            targets: [new Target('jq', 'jq', Target::ROLE_REFERENCE, 'jq-1.6', '/usr/bin/jq')],
            measurements: [
                new Measurement('jq', 'jq:startup', Measurement::STATUS_OK, 12, '', [1.0, 3.0], new SampleStats(2, 1.0, 2.0, 2.0, 2.9, 3.0, 1.4142)),
                new Measurement('phpxq-jq', 'jq:startup', Measurement::STATUS_NOT_IMPLEMENTED, 0, 'jq: not implemented', [], null),
            ],
        );

        $store = new ResultStore();
        $store->save($this->file, $record);

        $loaded = $store->load($this->file);

        self::assertEquals($record, $loaded);
    }

    public function testSaveCreatesMissingDirectories(): void
    {
        $nested = sys_get_temp_dir() . '/phpxq-bench-dir-' . bin2hex(random_bytes(4)) . '/a/b.json';
        $record = new RunRecord('x', 'now', [], [], [], []);

        new ResultStore()->save($nested, $record);

        self::assertFileExists($nested);
        unlink($nested);
        rmdir(\dirname($nested));
        rmdir(\dirname($nested, 2));
    }

    public function testLoadRejectsWrongSchema(): void
    {
        file_put_contents($this->file, '{"schema": 99}');

        $this->expectException(InvalidArgumentException::class);

        new ResultStore()->load($this->file);
    }

    public function testLoadRejectsMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResultStore()->load($this->file);
    }
}
