<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Release;

/**
 * CHANGELOG.md texts in the shapes the release flow meets.
 */
final class ChangelogFixture
{
    private const string INTRO = "# Changelog\n\nIntro text.\n\n";

    private const string PREVIOUS = "## 0.1.0 — 2026-01-02\n\n### Added\n\n- First entry.\n";

    /** @param array<string, list<string>> $sections heading => entries, in file order */
    public static function withUnreleased(array $sections, string $released = self::PREVIOUS): string
    {
        $body = '';
        foreach ($sections as $heading => $entries) {
            $body .= '### ' . $heading . "\n\n" . implode("\n", array_map(static fn (string $entry): string => '- ' . $entry, $entries)) . "\n\n";
        }

        return self::INTRO . "## Unreleased\n\n" . $body . $released;
    }

    public static function emptyUnreleased(string $released = self::PREVIOUS): string
    {
        return self::INTRO . "## Unreleased\n\n" . $released;
    }
}
