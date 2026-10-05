<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Release;

use LTS\PhpXq\Release\ReleaseException;
use LTS\PhpXq\Release\ReleaseVerifier;
use LTS\PhpXq\Release\VerdictEnum;
use LTS\PhpXq\Tests\Support\Release\ChangelogFixture;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReleaseVerifierTest extends TestCase
{
    public function testAgreeingVersionChangelogAndTagsAreReleasable(): void
    {
        $verdict = new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased(), "0.1.0\n", 'v0.0.9');

        self::assertSame(VerdictEnum::Releasable, $verdict);
    }

    public function testASectionWithoutADateStillCounts(): void
    {
        $verdict = new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased("## 0.1.0\n\n### Added\n\n- First entry.\n"), '0.1.0');

        self::assertSame(VerdictEnum::Releasable, $verdict);
    }

    public function testUnreleasedEntriesMeanThisIsNotAReleaseCommit(): void
    {
        $verdict = new ReleaseVerifier()->verify(ChangelogFixture::withUnreleased(['Added' => ['A thing.']]), '0.1.0');

        self::assertSame(VerdictEnum::NotAReleaseCommit, $verdict);
    }

    public function testVersionAheadOfTheChangelogIsRefused(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageIsOrContains('VERSION says 0.2.0 but the newest section of CHANGELOG.md is "## 0.1.0 — 2026-01-02"');

        new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased(), '0.2.0');
    }

    public function testVersionBehindTheChangelogIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased("## 0.2.0 — 2026-02-02\n\n### Added\n\n- Newer.\n\n## 0.1.0 — 2026-01-02\n\n### Added\n\n- First entry.\n"), '0.1.0');
    }

    public function testAChangelogWithNoReleasedSectionIsRefused(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageIsOrContains('(none)');

        new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased(''), '0.1.0');
    }

    public function testAnExistingTagIsRefused(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessageIsOrContains('tag v0.1.0 already exists');

        new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased(), '0.1.0', 'v0.1.0');
    }

    public function testAMalformedVersionFileIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased(), '0.1');
    }

    public function testAnUnreadableChangelogIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ReleaseVerifier()->verify("# Changelog\n\nNo unreleased section here.\n", '0.1.0');
    }

    public function testAnEmptyReleasedSectionIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ReleaseVerifier()->verify(ChangelogFixture::emptyUnreleased("## 0.1.0 — 2026-01-02\n\nNothing under a heading.\n"), '0.1.0');
    }
}
