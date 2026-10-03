<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

/**
 * The classified outcome of one suite run against a known-gaps list.
 */
final readonly class ConformanceReportResult
{
    private const int MAX_LISTED          = 20;

    private const int MAX_MESSAGE_LENGTH = 200;

    /**
     * @param array<string, string> $unexpectedFailures case id => failure message, for failures matching no gap
     * @param list<string>          $unexpectedPasses   ids of passing cases that match a gap
     * @param list<string>          $staleGlobs         gap globs that matched no case at all
     */
    public function __construct(
        public string $suite,
        public int $passed,
        public int $expectedFailures,
        public array $unexpectedFailures,
        public array $unexpectedPasses,
        public array $staleGlobs,
    ) {
    }

    public function total(): int
    {
        return $this->passed + $this->expectedFailures + \count($this->unexpectedFailures) + \count($this->unexpectedPasses);
    }

    public function hasProblems(): bool
    {
        return [] !== $this->unexpectedFailures || [] !== $this->unexpectedPasses;
    }

    /**
     * @return list<string>
     */
    public function summaryLines(): array
    {
        $lines = [\sprintf(
            '%s: %d cases | %d passed | %d expected failures (known gaps) | %d unexpected failures | %d unexpected passes',
            $this->suite,
            $this->total(),
            $this->passed,
            $this->expectedFailures,
            \count($this->unexpectedFailures),
            \count($this->unexpectedPasses),
        )];

        $problems = [];
        foreach ($this->unexpectedFailures as $id => $message) {
            $problems[] = 'UNEXPECTED FAILURE ' . $id . ': ' . $this->shorten($message);
        }

        foreach ($this->unexpectedPasses as $id) {
            $problems[] = 'UNEXPECTED PASS ' . $id . ' (matches a known gap, remove the gap)';
        }

        foreach (\array_slice($problems, 0, self::MAX_LISTED) as $problem) {
            $lines[] = $problem;
        }

        if (\count($problems) > self::MAX_LISTED) {
            $lines[] = '... and ' . (\count($problems) - self::MAX_LISTED) . ' more';
        }

        foreach ($this->staleGlobs as $glob) {
            $lines[] = 'WARNING stale known gap matched no case: ' . $glob;
        }

        return $lines;
    }

    private function shorten(string $message): string
    {
        $oneLine = trim((string)preg_replace('/\s+/', ' ', $message));

        return \strlen($oneLine) > self::MAX_MESSAGE_LENGTH ? substr($oneLine, 0, self::MAX_MESSAGE_LENGTH) . '...' : $oneLine;
    }
}
