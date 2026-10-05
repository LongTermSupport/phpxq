<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Release;

use LTS\PhpXq\Release\ChangelogReconciler;
use LTS\PhpXq\Release\ReleaseException;
use LTS\PhpXq\Tests\Support\Release\ChangelogFixture;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChangelogReconcilerTest extends TestCase
{
    private const string RELEASED = "## 0.2.0 — 2026-03-04\n\n### Added\n\n- Shipped thing.\n\n## 0.1.0 — 2026-01-02\n\n### Added\n\n- First entry.\n";

    public function testEntriesMainGainedSinceAreKeptUnderUnreleased(): void
    {
        $merged = new ChangelogReconciler()->reconcile(
            ChangelogFixture::emptyUnreleased(self::RELEASED),
            ChangelogFixture::withUnreleased(['Added' => ['Shipped thing.', 'Brand new.'], 'Fixed' => ['New fix.']], self::RELEASED),
        );

        self::assertSame(
            ChangelogFixture::withUnreleased(['Added' => ['Brand new.'], 'Fixed' => ['New fix.']], self::RELEASED),
            $merged,
        );
    }

    public function testEntriesTheReleaseAlreadyRecordedAreNotDuplicated(): void
    {
        $released = ChangelogFixture::emptyUnreleased(self::RELEASED);

        $merged = new ChangelogReconciler()->reconcile($released, ChangelogFixture::withUnreleased(['Added' => ['Shipped thing.']], self::RELEASED));

        self::assertSame($released, $merged);
    }

    public function testMultiLineEntriesSurviveIntact(): void
    {
        $main   = ChangelogFixture::withUnreleased(['Added' => ['Long entry that wraps' . "\n" . '  onto a second line.']], self::RELEASED);
        $merged = new ChangelogReconciler()->reconcile(ChangelogFixture::emptyUnreleased(self::RELEASED), $main);

        self::assertSame($main, $merged);
    }

    public function testAReleaseChangelogThatIsNotReleasedIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ChangelogReconciler()->reconcile(ChangelogFixture::withUnreleased(['Added' => ['Still here.']]), ChangelogFixture::emptyUnreleased());
    }

    public function testAnInvalidMainChangelogIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ChangelogReconciler()->reconcile(ChangelogFixture::emptyUnreleased(), "# Changelog\n");
    }
}
