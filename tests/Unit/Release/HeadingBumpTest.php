<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Release;

use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PhpXq\Release\BumpEnum;
use LTS\PhpXq\Release\HeadingBump;
use LTS\PhpXq\Release\SemVer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HeadingBumpTest extends TestCase
{
    #[DataProvider('provideTable')]
    public function testEveryHeadingMapsToItsBump(ChangelogHeadingEnum $heading, string $current, BumpEnum $expected): void
    {
        self::assertSame($expected, new HeadingBump()->forHeading($heading, SemVer::parse($current)));
    }

    /**
     * @return iterable<string, array{ChangelogHeadingEnum, string, BumpEnum}>
     */
    public static function provideTable(): iterable
    {
        yield 'breaking in 0.x is a minor' => [ChangelogHeadingEnum::ChangedBreaking, '0.4.2', BumpEnum::Minor];
        yield 'breaking from 1.0 is a major' => [ChangelogHeadingEnum::ChangedBreaking, '1.0.0', BumpEnum::Major];
        yield 'breaking in 2.x is a major' => [ChangelogHeadingEnum::ChangedBreaking, '2.7.1', BumpEnum::Major];
        yield 'removed is a minor' => [ChangelogHeadingEnum::Removed, '1.2.3', BumpEnum::Minor];
        yield 'added is a minor' => [ChangelogHeadingEnum::Added, '1.2.3', BumpEnum::Minor];
        yield 'changed is a minor' => [ChangelogHeadingEnum::Changed, '1.2.3', BumpEnum::Minor];
        yield 'deprecated is a minor' => [ChangelogHeadingEnum::Deprecated, '1.2.3', BumpEnum::Minor];
        yield 'fixed is a patch' => [ChangelogHeadingEnum::Fixed, '1.2.3', BumpEnum::Patch];
        yield 'security is a patch' => [ChangelogHeadingEnum::Security, '1.2.3', BumpEnum::Patch];
    }

    public function testTheTableCoversEveryHeadingTheLaneKnows(): void
    {
        $mapper = new HeadingBump();
        foreach (ChangelogHeadingEnum::cases() as $heading) {
            self::assertContains($mapper->forHeading($heading, SemVer::parse('1.0.0')), BumpEnum::cases());
        }
    }
}
