<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

use LTS\PhpXq\Tests\Support\CliRunner;

/**
 * Runs a conformance suite and classifies each case against a known-gaps list.
 */
final readonly class ConformanceReport
{
    /**
     * Entries with this glob prefix belong to the shell runner and are invisible here.
     */
    private const string SHELL_PREFIX = 'shell:';

    public function run(ConformanceSuiteInterface $suite, CliRunner $runner, GapList $gaps): ConformanceReportResult
    {
        $gaps       = $gaps->withoutGlobPrefix(self::SHELL_PREFIX);
        $passed     = 0;
        $expected   = 0;
        $failures   = [];
        $unexpected = [];
        $ids        = [];

        foreach ($suite->cases() as $case) {
            $ids[]   = $case->id;
            $message = $case->evaluate($runner);
            $isGap   = null !== $gaps->reasonFor($case->id);

            if (null === $message) {
                if ($isGap) {
                    $unexpected[] = $case->id;
                } else {
                    ++$passed;
                }

                continue;
            }

            if ($isGap) {
                ++$expected;
            } else {
                $failures[$case->id] = $message;
            }
        }

        $stale = [];
        foreach ($gaps->entries() as $entry) {
            if ([] === array_filter($ids, $entry->matches(...))) {
                $stale[] = $entry->glob;
            }
        }

        return new ConformanceReportResult($suite->name(), $passed, $expected, $failures, $unexpected, $stale);
    }
}
