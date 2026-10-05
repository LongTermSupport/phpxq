<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ReleaseBumpEnum;

/**
 * The heading-to-bump table of the `changelog` lane, with the one difference phpxq needs: php-qa-ci's major
 * is the PHP line, so a breaking change only ever moves its minor, whereas phpxq follows plain SemVer.
 *
 * - `Changed — breaking`: minor while the major is 0 (anything may change in 0.x), major from 1.0.
 * - Everything the lane calls a minor (`Removed`, `Added`, `Changed`, `Deprecated`): minor.
 * - `Fixed`, `Security`: patch.
 */
final readonly class HeadingBump
{
    public function forHeading(ChangelogHeadingEnum $heading, SemVer $current): BumpEnum
    {
        if (ChangelogHeadingEnum::ChangedBreaking === $heading) {
            return $current->major >= 1 ? BumpEnum::Major : BumpEnum::Minor;
        }

        return match ($heading->bump()) {
            ReleaseBumpEnum::Minor => BumpEnum::Minor,
            ReleaseBumpEnum::Patch => BumpEnum::Patch,
        };
    }
}
