<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Conformance;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceReport;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceSuiteInterface;
use LTS\PhpXq\Tests\Support\Conformance\GapList;
use LTS\PhpXq\Tests\Support\Conformance\JqConformanceSuite;
use LTS\PhpXq\Tests\Support\Conformance\YqConformanceSuite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate: every upstream case of a suite either passes or is a recorded, justified known gap. Unlike the
 * raw per-case suites (which ignore known-gaps.txt), this fails on an unexpected failure and on a known gap
 * that now passes, exactly as scripts/conformance-report.php does.
 *
 * @internal
 */
final class GapAwareConformanceTest extends TestCase
{
    #[DataProvider('provideSuites')]
    public function testEveryCaseIsAsExpected(ConformanceSuiteInterface $suite, string $gapsFile): void
    {
        $result = new ConformanceReport()->run($suite, new CliRunner(), GapList::fromFile($gapsFile));

        self::assertFalse($result->hasProblems(), implode("\n", $result->summaryLines()));
    }

    /**
     * @return iterable<string, array{ConformanceSuiteInterface, string}>
     */
    public static function provideSuites(): iterable
    {
        yield 'jq' => [new JqConformanceSuite(), __DIR__ . '/Jq/known-gaps.txt'];

        yield 'yq' => [new YqConformanceSuite(), __DIR__ . '/Yq/known-gaps.txt'];
    }
}
