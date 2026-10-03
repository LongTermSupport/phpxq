<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Conformance;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceCase;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceReport;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceSuiteInterface;
use LTS\PhpXq\Tests\Support\Conformance\GapList;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ConformanceReportTest extends TestCase
{
    public function testClassifiesEveryCase(): void
    {
        $suite = $this->suite([
            'ok'          => null,
            'gap.fails'   => 'nope',
            'new.fails'   => 'regression',
            'gap.passes'  => null,
            'also.passes' => null,
        ]);
        $gaps = GapList::fromLines(["gap.*\tknown", "stale.glob\told"]);

        $result = new ConformanceReport()->run($suite, new CliRunner(), $gaps);

        self::assertSame('jq', $result->suite);
        self::assertSame(5, $result->total());
        self::assertSame(2, $result->passed);
        self::assertSame(1, $result->expectedFailures);
        self::assertSame(['new.fails' => 'regression'], $result->unexpectedFailures);
        self::assertSame(['gap.passes'], $result->unexpectedPasses);
        self::assertSame(['stale.glob'], $result->staleGlobs);
        self::assertTrue($result->hasProblems());
    }

    public function testAllExpectedFailuresIsNoProblem(): void
    {
        $result = new ConformanceReport()->run(
            $this->suite(['a' => 'x', 'b' => 'y']),
            new CliRunner(),
            GapList::fromLines(["*\tblanket"]),
        );

        self::assertFalse($result->hasProblems());
        self::assertSame(2, $result->expectedFailures);
        self::assertSame(
            ['jq: 2 cases | 0 passed | 2 expected failures (known gaps) | 0 unexpected failures | 0 unexpected passes'],
            $result->summaryLines(),
        );
    }

    public function testShellEntriesAreIgnoredForStaleDetection(): void
    {
        $result = new ConformanceReport()->run(
            $this->suite(['a' => null]),
            new CliRunner(),
            GapList::fromLines(["shell:foo\tbelongs to the shell runner", "unused\tstale"]),
        );

        self::assertSame(['unused'], $result->staleGlobs);
        self::assertFalse($result->hasProblems());
    }

    public function testShellEntriesNeverMatchCases(): void
    {
        $result = new ConformanceReport()->run(
            $this->suite(['shell:x' => 'fails']),
            new CliRunner(),
            GapList::fromLines(["shell:*\tshell"]),
        );

        self::assertSame(1, $result->total());
        self::assertSame(['shell:x' => 'fails'], $result->unexpectedFailures);
    }

    public function testSummaryListsProblemsAndWarnings(): void
    {
        $result = new ConformanceReport()->run(
            $this->suite(['bad' => str_repeat('m', 500), 'p' => null]),
            new CliRunner(),
            GapList::fromLines(["p\tgap", "stale\told"]),
        );

        $lines = $result->summaryLines();

        self::assertSame('jq: 2 cases | 0 passed | 0 expected failures (known gaps) | 1 unexpected failures | 1 unexpected passes', $lines[0]);
        self::assertStringContainsString('UNEXPECTED FAILURE bad: ' . str_repeat('m', 20), $lines[1]);
        self::assertLessThan(260, \strlen($lines[1]));
        self::assertSame('UNEXPECTED PASS p (matches a known gap, remove the gap)', $lines[2]);
        self::assertSame('WARNING stale known gap matched no case: stale', $lines[3]);
    }

    public function testFailureMessageIsCollapsedToOneLine(): void
    {
        $result = new ConformanceReport()->run($this->suite(['bad' => "line1\nline2"]), new CliRunner(), GapList::fromLines([]));

        self::assertStringContainsString('bad: line1 line2', $result->summaryLines()[1]);
        self::assertSame(['bad' => "line1\nline2"], $result->unexpectedFailures);
    }

    public function testListsAtMostTwentyUnexpectedIds(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 25; ++$i) {
            $outcomes['c' . $i] = 'x';
        }

        $lines = new ConformanceReport()->run($this->suite($outcomes), new CliRunner(), GapList::fromLines([]))->summaryLines();

        self::assertCount(1 + 20 + 1, $lines);
        self::assertSame('... and 5 more', $lines[21]);
    }

    /**
     * @param array<string, ?string> $outcomes case id => failure message (null passes)
     */
    private function suite(array $outcomes): ConformanceSuiteInterface
    {
        return new readonly class($outcomes) implements ConformanceSuiteInterface {
            /**
             * @param array<string, ?string> $outcomes
             */
            public function __construct(private array $outcomes)
            {
            }

            public function name(): string
            {
                return 'jq';
            }

            public function cases(): iterable
            {
                foreach ($this->outcomes as $id => $message) {
                    yield new ConformanceCase($id, static fn (CliRunner $runner): ?string => $message);
                }
            }
        };
    }
}
