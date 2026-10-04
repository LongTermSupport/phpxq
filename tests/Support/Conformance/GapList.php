<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

use InvalidArgumentException;

/**
 * A parsed known-gaps file. The format is shared with the shell conformance runner: UTF-8 text, blank
 * lines and lines starting with `#` ignored, every other line `<glob><TAB><reason>` where the glob uses
 * fnmatch semantics against the case id and the reason is mandatory.
 */
final readonly class GapList
{
    /**
     * @param list<GapEntry> $entries
     */
    private function __construct(private array $entries)
    {
    }

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (false === $contents) {
            throw new InvalidArgumentException('Cannot read known gaps file ' . $path);
        }

        return self::fromLines(...explode("\n", $contents));
    }

    public static function fromLines(string ...$lines): self
    {
        $entries = [];
        foreach (array_values($lines) as $index => $rawLine) {
            $line = rtrim($rawLine, "\r");
            if ('' === trim($line) || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line, 2);
            if (2 !== \count($parts)) {
                throw new InvalidArgumentException(\sprintf('Known gaps line %d: expected "<glob><TAB><reason>", got "%s"', $index + 1, $line));
            }

            [$glob, $reason] = $parts;
            if ('' === $glob || '' === trim($reason)) {
                throw new InvalidArgumentException(\sprintf('Known gaps line %d: both the glob and the reason must be non-empty', $index + 1));
            }

            $entries[] = new GapEntry($glob, trim($reason));
        }

        return new self($entries);
    }

    /**
     * @return list<GapEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The justification of the first entry matching the case id, or null when the id is not a known gap.
     */
    public function reasonFor(string $id): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry->matches($id)) {
                return $entry->reason;
            }
        }

        return null;
    }

    /**
     * A copy without the entries whose glob starts with the prefix (they belong to another runner).
     */
    public function withoutGlobPrefix(string $prefix): self
    {
        return new self(array_values(array_filter(
            $this->entries,
            static fn (GapEntry $entry): bool => !str_starts_with($entry->glob, $prefix),
        )));
    }
}
