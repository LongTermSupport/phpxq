<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * Aggregates stack samples into self and inclusive shares per function. The profiling tool of Plan 00007: no
 * Xdebug is needed, a signal handler (see scripts/bench/profile.php) calls {@see self::record()} with the output
 * of `debug_backtrace()` a few hundred times a second.
 */
final class SamplingProfiler
{
    /** @var array<string, int> */
    private array $self = [];

    /** @var array<string, int> */
    private array $inclusive = [];

    private int $samples = 0;

    /**
     * @param list<array{class?: string, type?: string, function: string, file?: string}> $frames innermost first
     */
    public function record(array $frames): void
    {
        if ([] === $frames) {
            return;
        }

        ++$this->samples;
        $seen = [];
        foreach ($frames as $position => $frame) {
            $name = $this->name($frame);
            if (0 === $position) {
                $this->self[$name] = ($this->self[$name] ?? 0) + 1;
            }

            if (!isset($seen[$name])) {
                $seen[$name]            = true;
                $this->inclusive[$name] = ($this->inclusive[$name] ?? 0) + 1;
            }
        }
    }

    public function samples(): int
    {
        return $this->samples;
    }

    /**
     * @return array<string, int> samples whose innermost frame is the function, most first
     */
    public function self(): array
    {
        return $this->ranked($this->self);
    }

    /**
     * @return array<string, int> samples with the function anywhere on the stack, most first
     */
    public function inclusive(): array
    {
        return $this->ranked($this->inclusive);
    }

    /**
     * @param int $top rows per table
     */
    public function report(int $top): string
    {
        $out = 'samples: ' . $this->samples . "\n";
        foreach (['SELF' => $this->self(), 'INCLUSIVE' => $this->inclusive()] as $title => $table) {
            $out .= $title . "\n";
            foreach (\array_slice($table, 0, $top, true) as $name => $count) {
                $out .= \sprintf("  %5.1f%% %s\n", 100 * $count / max(1, $this->samples), $name);
            }
        }

        return $out;
    }

    /**
     * @param array{class?: string, type?: string, function: string, file?: string} $frame
     */
    private function name(array $frame): string
    {
        if (str_contains($frame['function'], '{closure')) {
            return ($frame['class'] ?? '') . ('' !== ($frame['class'] ?? '') ? '::' : '') . '{closure}@' . basename($frame['file'] ?? '?');
        }

        return ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function ranked(array $counts): array
    {
        arsort($counts);

        return $counts;
    }
}
