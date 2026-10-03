<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use DateTimeZone;
use LTS\PhpXq\Jq\Builtin\Date\Civil;
use LTS\PhpXq\Jq\Builtin\Date\TimeZones;
use LTS\PhpXq\Jq\Builtin\Date\ZoneInfo;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TimeZonesTest extends TestCase
{
    private string|false $originalZone = false;

    protected function setUp(): void
    {
        $this->originalZone = getenv('TZ');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->originalZone ? 'TZ' : 'TZ=' . $this->originalZone);
    }

    public function testFixedZone(): void
    {
        $zone = ZoneInfo::fixed(-18000, 'EST');

        self::assertSame([-18000, false, 'EST'], $zone->at(1_700_000_000));
        self::assertSame(1_700_000_000, $zone->epochOfWallClock(1_700_000_000 - 18000));
    }

    public function testUtcZone(): void
    {
        self::assertSame([0, false, 'UTC'], ZoneInfo::utc()->at(0));
        self::assertSame(12345, ZoneInfo::utc()->epochOfWallClock(12345));
    }

    public function testNamedZoneOffsetsAbbreviationsAndDst(): void
    {
        $paris = ZoneInfo::named(new DateTimeZone('Europe/Paris'));

        self::assertSame([3600, false, 'CET'], $paris->at(1731627341));
        self::assertSame([7200, true, 'CEST'], $paris->at(1750500000));
    }

    public function testWallClockToInstantAroundDstChanges(): void
    {
        $paris = ZoneInfo::named(new DateTimeZone('Europe/Paris'));

        // 2025-06-21 12:00 local is 10:00 UTC
        self::assertSame(1750500000, $paris->epochOfWallClock(1750500000 + 7200));
        // 2025-03-30 02:30 does not exist; the offset before the change (+1) is used
        self::assertSame(Civil::timegm(2025, 2, 30, 1, 30, 0), $paris->epochOfWallClock(Civil::timegm(2025, 2, 30, 2, 30, 0)));
        // 2025-10-26 02:30 happens twice; the later one (CET, +1) is used
        self::assertSame(Civil::timegm(2025, 9, 26, 1, 30, 0), $paris->epochOfWallClock(Civil::timegm(2025, 9, 26, 2, 30, 0)));
    }

    public function testResolveNamedSpecs(): void
    {
        self::assertSame([32400, false, 'JST'], TimeZones::resolve('Asia/Tokyo')->at(0));
        self::assertSame([32400, false, 'JST'], TimeZones::resolve(':Asia/Tokyo')->at(0));
        self::assertSame([0, false, 'UTC'], TimeZones::resolve('UTC')->at(0));
        self::assertSame([-25200, false, '-07'], TimeZones::resolve('Etc/GMT+7')->at(0));
    }

    public function testResolvePosixSpecs(): void
    {
        self::assertSame([32400, false, 'JST'], TimeZones::resolve('JST-9')->at(0));
        self::assertSame([-18000, false, 'EST'], TimeZones::resolve('EST5')->at(0));
        self::assertSame([19800, false, 'IST'], TimeZones::resolve('IST-5:30')->at(0));
        self::assertSame([-10800, false, '-03'], TimeZones::resolve('<-03>3')->at(0));
    }

    public function testEmptyAndUnknownSpecsAreUtc(): void
    {
        self::assertSame([0, false, 'UTC'], TimeZones::resolve('')->at(0));
        self::assertSame([0, false, 'UTC'], TimeZones::resolve(':')->at(0));
        self::assertSame([0, false, 'UTC'], TimeZones::resolve('Not/AZone')->at(0));
    }

    public function testLocalFollowsTheEnvironmentVariable(): void
    {
        putenv('TZ=Asia/Tokyo');
        self::assertSame([32400, false, 'JST'], TimeZones::local()->at(0));

        putenv('TZ=Europe/Paris');
        self::assertSame([3600, false, 'CET'], TimeZones::local()->at(0));

        putenv('TZ=');
        self::assertSame([0, false, 'UTC'], TimeZones::local()->at(0));
    }

    public function testUnsetEnvironmentVariableUsesTheHostDefault(): void
    {
        putenv('TZ');

        $offset = TimeZones::local()->at(0)[0];

        self::assertSame(0, $offset % 900);
    }
}
