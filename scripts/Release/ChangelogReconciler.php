<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;

/**
 * Merges a released changelog back into a main whose `## Unreleased` has moved on.
 *
 * The release branch carries the released section and an empty `## Unreleased`. Main may have gained entries
 * since the release pull request was prepared, and a plain merge conflicts on them. The result keeps the
 * release branch's file and puts back every entry main has under `## Unreleased` that the release did not
 * already record, so nothing is lost and nothing is recorded twice.
 */
final readonly class ChangelogReconciler
{
    public function __construct(private ChangelogParser $parser = new ChangelogParser())
    {
    }

    public function reconcile(string $releaseChangelog, string $mainChangelog): string
    {
        $release = $this->parse($releaseChangelog);
        if (!$release->isEmpty()) {
            throw new ReleaseException('the release branch changelog still has entries under "## Unreleased"; it is not a released changelog');
        }

        $sections = [];
        foreach ($this->parse($mainChangelog)->blocks as $block) {
            $kept = array_values(array_filter(
                $block->entries,
                static fn (string $entry): bool => !str_contains($releaseChangelog, $entry),
            ));
            if ([] !== $kept) {
                $sections[] = '### ' . $block->heading->value . "\n\n" . implode("\n", $kept);
            }
        }

        if ([] === $sections) {
            return $releaseChangelog;
        }

        $before = \array_slice($release->lines, 0, $release->unreleasedIndex + 1);
        $after  = \array_slice($release->lines, $release->sectionEnd);

        return implode("\n", $before) . "\n\n" . implode("\n\n", $sections) . "\n\n" . implode("\n", $after);
    }

    private function parse(string $changelog): ChangelogDocumentDto
    {
        try {
            return $this->parser->parse($changelog);
        } catch (InvalidChangelogException $invalidChangelogException) {
            throw new ReleaseException($invalidChangelogException->getMessage(), 0, $invalidChangelogException);
        }
    }
}
