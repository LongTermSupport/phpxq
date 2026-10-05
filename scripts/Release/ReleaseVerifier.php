<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;

/**
 * The gate in front of tagging. A release is only cut when VERSION, the newest released section of
 * CHANGELOG.md and the tags all agree; anything else is a refusal that names the disagreement.
 */
final readonly class ReleaseVerifier
{
    public function __construct(private ChangelogParser $parser = new ChangelogParser())
    {
    }

    /**
     * @param string ...$tags every tag of the repository
     *
     * @throws ReleaseException when VERSION and CHANGELOG.md disagree, the section is unreadable or the tag exists
     */
    public function verify(string $changelog, string $versionFile, string ...$tags): VerdictEnum
    {
        $version = SemVer::parse(trim($versionFile));

        try {
            $document = $this->parser->parse($changelog);
        } catch (InvalidChangelogException $invalidChangelogException) {
            throw new ReleaseException($invalidChangelogException->getMessage(), 0, $invalidChangelogException);
        }

        if (!$document->isEmpty()) {
            return VerdictEnum::NotAReleaseCommit;
        }

        $newest = $document->lines[$document->sectionEnd] ?? null;
        $prefix = ChangelogParser::SECTION_PREFIX . $version->toString();
        $line   = null === $newest ? '' : rtrim($newest);
        if ($prefix !== $line && !str_starts_with($line, $prefix . ' ')) {
            throw new ReleaseException(\sprintf(
                'VERSION says %s but the newest section of CHANGELOG.md is "%s". They must agree; release through the release pull request rather than editing either by hand.',
                $version->toString(),
                '' === $line ? '(none)' : $line,
            ));
        }

        try {
            $this->parser->release($changelog, $version->toString());
        } catch (InvalidChangelogException $invalidChangelogException) {
            throw new ReleaseException($invalidChangelogException->getMessage(), 0, $invalidChangelogException);
        }

        $tag = 'v' . $version->toString();
        if (\in_array($tag, $tags, true)) {
            throw new ReleaseException(\sprintf('tag %s already exists, so %s was already released. Bump VERSION through a new release.', $tag, $version->toString()));
        }

        return VerdictEnum::Releasable;
    }
}
