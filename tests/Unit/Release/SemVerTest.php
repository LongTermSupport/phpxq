<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Release;

use LTS\PhpXq\Release\BumpEnum;
use LTS\PhpXq\Release\ReleaseException;
use LTS\PhpXq\Release\SemVer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SemVerTest extends TestCase
{
    public function testParsesAndPrintsAPlainVersion(): void
    {
        $version = SemVer::parse('12.0.7');

        self::assertSame(12, $version->major);
        self::assertSame(0, $version->minor);
        self::assertSame(7, $version->patch);
        self::assertSame('12.0.7', $version->toString());
    }

    #[DataProvider('provideRefusedVersions')]
    public function testRefusesAnythingButPlainSemVer(string $version): void
    {
        $this->expectException(ReleaseException::class);

        SemVer::parse($version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['1.2'];
        yield 'four parts' => ['1.2.3.4'];
        yield 'leading v' => ['v1.2.3'];
        yield 'pre-release' => ['1.2.3-rc.1'];
        yield 'build metadata' => ['1.2.3+build'];
        yield 'leading zero' => ['01.2.3'];
        yield 'trailing newline' => ["1.2.3\n"];
    }

    #[DataProvider('provideBumps')]
    public function testBumpResetsTheLowerParts(string $from, BumpEnum $bump, string $expected): void
    {
        self::assertSame($expected, SemVer::parse($from)->bump($bump)->toString());
    }

    /**
     * @return iterable<string, array{string, BumpEnum, string}>
     */
    public static function provideBumps(): iterable
    {
        yield 'patch' => ['1.2.3', BumpEnum::Patch, '1.2.4'];
        yield 'minor resets patch' => ['1.2.3', BumpEnum::Minor, '1.3.0'];
        yield 'major resets minor and patch' => ['1.2.3', BumpEnum::Major, '2.0.0'];
        yield 'minor past nine is numeric' => ['0.9.4', BumpEnum::Minor, '0.10.0'];
    }

    public function testStrongestPrefersTheBiggerBump(): void
    {
        self::assertSame(BumpEnum::Major, BumpEnum::Patch->strongest(BumpEnum::Major));
        self::assertSame(BumpEnum::Major, BumpEnum::Major->strongest(BumpEnum::Minor));
        self::assertSame(BumpEnum::Minor, BumpEnum::Patch->strongest(BumpEnum::Minor));
        self::assertSame(BumpEnum::Patch, BumpEnum::Patch->strongest(BumpEnum::Patch));
    }
}
