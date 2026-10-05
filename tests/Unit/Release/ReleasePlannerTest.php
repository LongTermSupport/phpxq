<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Release;

use LTS\PhpXq\Release\ReleaseException;
use LTS\PhpXq\Release\ReleasePlan;
use LTS\PhpXq\Release\ReleasePlanner;
use LTS\PhpXq\Release\SemVer;
use LTS\PhpXq\Tests\Support\Release\ChangelogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReleasePlannerTest extends TestCase
{
    /**
     * @param array<string, list<string>> $sections
     */
    #[DataProvider('provideNextVersions')]
    public function testNextVersionFollowsTheStrongestHeading(string $current, array $sections, string $expected): void
    {
        $next = new ReleasePlanner()->nextVersion(ChangelogFixture::withUnreleased($sections), SemVer::parse($current), true);

        self::assertInstanceOf(SemVer::class, $next);
        self::assertSame($expected, $next->toString());
    }

    /**
     * @return iterable<string, array{string, array<string, list<string>>, string}>
     */
    public static function provideNextVersions(): iterable
    {
        yield 'fix only is a patch' => ['0.1.0', ['Fixed' => ['A bug.']], '0.1.1'];
        yield 'security only is a patch' => ['1.4.2', ['Security' => ['A hole.']], '1.4.3'];
        yield 'feature is a minor' => ['0.1.4', ['Added' => ['A thing.']], '0.2.0'];
        yield 'feature beats fix' => ['1.1.1', ['Fixed' => ['A bug.'], 'Added' => ['A thing.']], '1.2.0'];
        yield 'deprecation is a minor' => ['1.0.0', ['Deprecated' => ['A flag.']], '1.1.0'];
        yield 'removal is a minor' => ['1.0.0', ['Removed' => ['A flag.']], '1.1.0'];
        yield 'breaking in 0.x is a minor' => ['0.3.1', ['Changed — breaking' => ['A contract.'], 'Fixed' => ['A bug.']], '0.4.0'];
        yield 'breaking from 1.0 is a major' => ['1.3.1', ['Changed — breaking' => ['A contract.'], 'Added' => ['A thing.']], '2.0.0'];
    }

    public function testAnUnreleasedVersionIsReleasedAsItIsWithoutABump(): void
    {
        $next = new ReleasePlanner()->nextVersion(ChangelogFixture::withUnreleased(['Fixed' => ['A bug.']]), SemVer::parse('0.1.0'), false);

        self::assertInstanceOf(SemVer::class, $next);
        self::assertSame('0.1.0', $next->toString());
    }

    public function testAnEmptyUnreleasedSectionReleasesNothing(): void
    {
        $planner = new ReleasePlanner();

        self::assertNull($planner->nextVersion(ChangelogFixture::emptyUnreleased(), SemVer::parse('0.1.0'), true));
        self::assertNull($planner->plan(ChangelogFixture::emptyUnreleased(), SemVer::parse('0.1.0'), false, '2026-03-04'));
    }

    public function testPlanMovesTheEntriesIntoADatedSectionAndKeepsTheRest(): void
    {
        $plan = new ReleasePlanner()->plan(
            ChangelogFixture::withUnreleased(['Added' => ['A thing.'], 'Fixed' => ['A bug.']]),
            SemVer::parse('0.1.0'),
            true,
            '2026-03-04',
        );

        self::assertInstanceOf(ReleasePlan::class, $plan);
        self::assertSame('0.2.0', $plan->version->toString());
        self::assertSame(
            ChangelogFixture::emptyUnreleased(
                "## 0.2.0 — 2026-03-04\n\n### Added\n\n- A thing.\n\n### Fixed\n\n- A bug.\n\n## 0.1.0 — 2026-01-02\n\n### Added\n\n- First entry.\n",
            ),
            $plan->changelog,
        );
    }

    public function testPlanForAnUnreleasedVersionWritesThatVersion(): void
    {
        $plan = new ReleasePlanner()->plan(ChangelogFixture::withUnreleased(['Added' => ['A thing.']], ''), SemVer::parse('0.1.0'), false, '2026-03-04');

        self::assertInstanceOf(ReleasePlan::class, $plan);
        self::assertSame('0.1.0', $plan->version->toString());
        self::assertStringContainsString("## 0.1.0 — 2026-03-04\n\n### Added\n\n- A thing.\n", $plan->changelog);
    }

    public function testAnInvalidUnreleasedSectionIsRefusedNotGuessedAt(): void
    {
        $this->expectException(ReleaseException::class);

        new ReleasePlanner()->nextVersion(ChangelogFixture::withUnreleased(['Improved' => ['A thing.']]), SemVer::parse('0.1.0'), true);
    }

    public function testABadDateIsRefused(): void
    {
        $this->expectException(ReleaseException::class);

        new ReleasePlanner()->plan(ChangelogFixture::withUnreleased(['Added' => ['A thing.']]), SemVer::parse('0.1.0'), true, 'yesterday');
    }
}
