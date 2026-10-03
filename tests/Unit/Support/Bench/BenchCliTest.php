<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use LTS\PhpXq\Tests\Support\Bench\BenchCli;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BenchCliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpxq-benchcli-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array)scandir($this->dir) as $entry) {
            if (is_file($this->dir . '/' . $entry)) {
                unlink($this->dir . '/' . $entry);
            }
        }

        rmdir($this->dir);
    }

    public function testPlanGeneratesCorporaAndPrintsTsv(): void
    {
        [$code, $out] = $this->invoke(['plan', '--corpus-dir', $this->dir, '--tools', 'jq', '--sizes', 'small', '--filter', 'identity']);

        self::assertSame(0, $code);
        self::assertSame(
            "jq:identity-small\tjq\t" . $this->dir . "/small.json\t1\t.\n",
            $out,
        );
        self::assertFileExists($this->dir . '/small.json');
    }

    public function testRecordWritesJsonAndMarkdownReport(): void
    {
        file_put_contents($this->dir . '/targets.tsv', "phpxq-jq\tjq\tsubject\t\tphp bin/phpxq jq\njq\tjq\treference\tjq-1.6\t/usr/bin/jq\n");
        file_put_contents(
            $this->dir . '/raw.tsv',
            "phpxq-jq\tjq:startup\tnot-implemented\t0\t\tjq: not implemented\njq\tjq:startup\tok\t3\t4.0,6.0\t\n",
        );
        $json = $this->dir . '/run.json';

        [$code, $out] = $this->invoke([
            'record', '--targets', $this->dir . '/targets.tsv', '--raw', $this->dir . '/raw.tsv',
            '--label', 'unit', '--started', '2026-10-03T00:00:00+00:00', '--out', $json,
            '--setting', 'repetitions=2', '--setting', 'warmup=1',
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString($json, $out);
        self::assertFileExists($json);
        self::assertFileExists($this->dir . '/run.md');
        $report = (string)file_get_contents($this->dir . '/run.md');
        self::assertStringContainsString('not-implemented', $report);
        self::assertStringContainsString('repetitions', $report);

        [$reportCode, $reportOut] = $this->invoke(['report', $json, '--baseline', $json]);
        self::assertSame(0, $reportCode);
        self::assertStringContainsString('1.00x', $reportOut);
    }

    public function testUnknownCommandPrintsUsage(): void
    {
        [$code, , $err] = $this->invoke(['bogus']);

        self::assertSame(2, $code);
        self::assertStringContainsString('usage:', $err);
    }

    public function testMissingRequiredOptionFails(): void
    {
        [$code, , $err] = $this->invoke(['record']);

        self::assertSame(2, $code);
        self::assertStringContainsString('--targets', $err);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function invoke(array $args): array
    {
        $out  = $this->stream();
        $err  = $this->stream();
        $code = new BenchCli()->run($args, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        return $stream;
    }
}
