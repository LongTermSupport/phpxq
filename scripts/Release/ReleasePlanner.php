<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\ChangelogReleaseWriter;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;

/**
 * Chooses the next version from the headings of `## Unreleased` and writes the release into the changelog.
 *
 * VERSION holds the newest version that was released, or, before the very first release, the version the
 * first release will have. So when VERSION has no tag yet, the release is VERSION itself and no bump is
 * applied; otherwise the strongest bump among the headings is applied to it.
 */
final readonly class ReleasePlanner
{
    public function __construct(
        private ChangelogParser $parser = new ChangelogParser(),
        private HeadingBump $headingBump = new HeadingBump(),
        private ChangelogReleaseWriter $writer = new ChangelogReleaseWriter(),
    ) {
    }

    /**
     * The version `## Unreleased` releases as, or null when it has no entries.
     *
     * @param bool $currentReleased whether the tag for $current exists
     */
    public function nextVersion(string $changelog, SemVer $current, bool $currentReleased): ?SemVer
    {
        $document = $this->parse($changelog);
        if ($document->isEmpty()) {
            return null;
        }

        if (!$currentReleased) {
            return $current;
        }

        $bump = null;
        foreach ($document->blocks as $block) {
            $blockBump = $this->headingBump->forHeading($block->heading, $current);
            $bump      = $bump instanceof BumpEnum ? $bump->strongest($blockBump) : $blockBump;
        }

        return $bump instanceof BumpEnum ? $current->bump($bump) : null;
    }

    /** The release as a plan (version plus rewritten changelog), or null when nothing is unreleased. */
    public function plan(string $changelog, SemVer $current, bool $currentReleased, string $date): ?ReleasePlan
    {
        $next = $this->nextVersion($changelog, $current, $currentReleased);
        if (!$next instanceof SemVer) {
            return null;
        }

        try {
            $released = $this->writer->apply($this->parse($changelog), $next->toString(), $date);
        } catch (ChangelogReleaseException $changelogReleaseException) {
            throw new ReleaseException($changelogReleaseException->getMessage(), 0, $changelogReleaseException);
        }

        return new ReleasePlan($next, $released);
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
