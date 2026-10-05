<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

/**
 * The subcommands of scripts/release.php.
 */
enum CommandEnum: string
{
    /** Print the version `## Unreleased` releases as; print nothing when it has no entries. */
    case NextVersion = 'next-version';

    /** Release `## Unreleased` into CHANGELOG.md and write the version to VERSION. */
    case Prepare = 'prepare';

    /** Print the release notes of a released version. */
    case Notes = 'notes';

    /** Refuse unless VERSION, CHANGELOG.md and the tags agree. */
    case Verify = 'verify';

    /** Print a released changelog merged with the entries main gained since. */
    case Reconcile = 'reconcile';
}
