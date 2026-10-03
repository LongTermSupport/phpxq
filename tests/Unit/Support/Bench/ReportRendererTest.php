<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use LTS\PhpXq\Tests\Support\Bench\Measurement;
use LTS\PhpXq\Tests\Support\Bench\ReportRenderer;
use LTS\PhpXq\Tests\Support\Bench\RunRecord;
use LTS\PhpXq\Tests\Support\Bench\SampleStats;
use LTS\PhpXq\Tests\Support\Bench\Target;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReportRendererTest extends TestCase
{
    public function testRendersEnvironmentTargetsAndPerWorkloadTables(): void
    {
        $report = new ReportRenderer()->render($this->record('now', 20.0, 10.0), null);

        self::assertStringContainsString('# phpxq benchmark report', $report);
        self::assertStringContainsString('php_version', $report);
        self::assertStringContainsString('Test CPU', $report);
        self::assertStringContainsString('### jq:startup', $report);
        self::assertStringContainsString('| phpxq-jq |', $report);
        self::assertStringContainsString('| jq |', $report);
        self::assertStringContainsString('20.00', $report);
    }

    public function testShowsRatioAgainstReferenceTool(): void
    {
        $report = new ReportRenderer()->render($this->record('now', 20.0, 10.0), null);

        self::assertStringContainsString('2.00x', $report);
    }

    public function testShowsRatioAgainstBaselineAndWarnsOnEnvironmentMismatch(): void
    {
        $baseline = $this->record('base', 40.0, 10.0, 'Other CPU');
        $report   = new ReportRenderer()->render($this->record('now', 20.0, 10.0), $baseline);

        self::assertStringContainsString('0.50x', $report);
        self::assertStringContainsString('different environment', $report);
    }

    public function testNoEnvironmentWarningWhenBaselineMatches(): void
    {
        $report = new ReportRenderer()->render($this->record('now', 20.0, 10.0), $this->record('base', 40.0, 10.0));

        self::assertStringNotContainsString('different environment', $report);
    }

    public function testNonOkStatusesAreShownWithoutNumbers(): void
    {
        $record = new RunRecord(
            'stub',
            'now',
            ['cpu_model' => 'Test CPU'],
            [],
            [new Target('phpxq-jq', 'jq', Target::ROLE_SUBJECT, '', 'php bin/phpxq jq'), new Target('jq', 'jq', Target::ROLE_REFERENCE, '', '')],
            [
                new Measurement('phpxq-jq', 'jq:startup', Measurement::STATUS_NOT_IMPLEMENTED, 0, 'jq: not implemented', [], null),
                new Measurement('jq', 'jq:startup', Measurement::STATUS_UNAVAILABLE, 0, 'not on PATH', [], null),
            ],
        );

        $report = new ReportRenderer()->render($record, null);

        self::assertStringContainsString('not-implemented', $report);
        self::assertStringContainsString('unavailable', $report);
        self::assertStringContainsString('jq: not implemented', $report);
    }

    private function record(string $label, float $subjectMedian, float $referenceMedian, string $cpu = 'Test CPU'): RunRecord
    {
        return new RunRecord(
            $label,
            '2026-10-03T12:00:00+00:00',
            ['php_version' => '8.5.0', 'cpu_model' => $cpu],
            ['repetitions' => '5'],
            [
                new Target('phpxq-jq', 'jq', Target::ROLE_SUBJECT, '', 'php bin/phpxq jq'),
                new Target('jq', 'jq', Target::ROLE_REFERENCE, 'jq-1.6', '/usr/bin/jq'),
            ],
            [
                new Measurement('phpxq-jq', 'jq:startup', Measurement::STATUS_OK, 3, '', [$subjectMedian], $this->stats($subjectMedian)),
                new Measurement('jq', 'jq:startup', Measurement::STATUS_OK, 3, '', [$referenceMedian], $this->stats($referenceMedian)),
            ],
        );
    }

    private function stats(float $median): SampleStats
    {
        return new SampleStats(1, $median, $median, $median, $median, $median, 0.0);
    }
}
