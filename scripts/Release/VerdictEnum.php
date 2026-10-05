<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

/**
 * What the release gate concluded about a commit that landed on the `release` branch. A refusal is not a
 * verdict: it is a ReleaseException.
 */
enum VerdictEnum
{
    /** VERSION, the newest changelog section and the (absent) tag agree: tag and publish it. */
    case Releasable;

    /** `## Unreleased` still has entries, so this is not a release commit (the branch was just created, or main was merged in by hand). */
    case NotAReleaseCommit;
}
