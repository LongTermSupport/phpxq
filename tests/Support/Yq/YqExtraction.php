<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

/**
 * Everything one extraction run found: the usable cases and the skipped examples.
 */
final readonly class YqExtraction
{
    /**
     * @param list<YqCase> $cases
     * @param list<YqSkip> $skipped
     */
    public function __construct(
        public array $cases,
        public array $skipped,
    ) {
    }

    public function merge(self $other): self
    {
        return new self([...$this->cases, ...$other->cases], [...$this->skipped, ...$other->skipped]);
    }

    public function casesJson(): string
    {
        $rows = [];
        foreach ($this->cases as $case) {
            $rows[] = $case->toArray();
        }

        return $this->encode(...$rows);
    }

    public function skippedJson(): string
    {
        $rows = [];
        foreach ($this->skipped as $skip) {
            $rows[] = $skip->toArray();
        }

        return $this->encode(...$rows);
    }

    /**
     * @param array<string, mixed> ...$rows
     */
    private function encode(array ...$rows): string
    {
        return json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }
}
