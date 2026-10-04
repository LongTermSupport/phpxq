<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use LTS\PhpXq\Tests\Support\Bench\SamplingProfiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SamplingProfiler::class)]
final class SamplingProfilerTest extends TestCase
{
    public function testSelfAndInclusiveShares(): void
    {
        $profiler = new SamplingProfiler();
        $profiler->record([
            ['class' => 'A', 'type' => '->', 'function' => 'leaf'],
            ['class' => 'A', 'type' => '->', 'function' => 'middle'],
            ['function' => 'main'],
        ]);
        $profiler->record([
            ['class' => 'A', 'type' => '->', 'function' => 'leaf'],
            ['function' => 'main'],
        ]);
        $profiler->record([
            ['function' => 'main'],
        ]);

        self::assertSame(3, $profiler->samples());
        self::assertSame(['A->leaf' => 2, 'main' => 1], $profiler->self());
        self::assertSame(['main' => 3, 'A->leaf' => 2, 'A->middle' => 1], $profiler->inclusive());
    }

    public function testARecursiveFunctionCountsOncePerSampleInclusively(): void
    {
        $profiler = new SamplingProfiler();
        $profiler->record([['function' => 'f'], ['function' => 'f'], ['function' => 'f']]);

        self::assertSame(['f' => 1], $profiler->inclusive());
        self::assertSame(['f' => 1], $profiler->self());
    }

    public function testClosuresAreNamedByTheirFile(): void
    {
        $profiler = new SamplingProfiler();
        $profiler->record([['class' => 'A', 'type' => '->', 'function' => '{closure:A->run():12}', 'file' => '/x/src/Thing.php']]);

        self::assertSame(['A::{closure}@Thing.php' => 1], $profiler->self());
    }

    public function testReportListsTheBusiestFirstWithPercentages(): void
    {
        $profiler = new SamplingProfiler();
        $profiler->record([['function' => 'hot']]);
        $profiler->record([['function' => 'hot']]);
        $profiler->record([['function' => 'cold']]);

        $report = $profiler->report(5);

        self::assertStringContainsString('samples: 3', $report);
        self::assertLessThan(strpos($report, 'cold'), strpos($report, 'hot'));
        self::assertStringContainsString(' 66.7% hot', $report);
    }

    public function testAnEmptyProfileReportsNothingToShow(): void
    {
        self::assertStringContainsString('samples: 0', new SamplingProfiler()->report(5));
    }
}
