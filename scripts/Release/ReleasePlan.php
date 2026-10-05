<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

/**
 * The outcome of preparing a release: the version to ship and the CHANGELOG.md that records it.
 */
final readonly class ReleasePlan
{
    public function __construct(
        public SemVer $version,
        public string $changelog,
    ) {
    }
}
