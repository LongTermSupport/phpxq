<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use LTS\PhpXq\Jq\Builtin\Date\BrokenDownTime;
use LTS\PhpXq\Jq\Builtin\Date\Civil;
use LTS\PhpXq\Jq\Builtin\Date\ZoneInfo;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BrokenDownTimeTest extends TestCase
{
    public function testFromEpochInUtc(): void
    {
        $time = BrokenDownTime::fromEpoch(1425599507, ZoneInfo::utc());

        self::assertNotNull($time);
        self::assertSame([2015, 2, 5, 23, 51, 47, 4, 63], $time->toList());
        self::assertSame(0, $time->gmtOffset);
        self::assertSame('UTC', $time->zone);
        self::assertFalse($time->dst);
    }

    public function testFromEpochBeforeTheEpoch(): void
    {
        $time = BrokenDownTime::fromEpoch(-1, ZoneInfo::utc());

        self::assertNotNull($time);
        self::assertSame([1969, 11, 31, 23, 59, 59, 3, 364], $time->toList());
    }

    public function testFromEpochInAFixedZone(): void
    {
        $time = BrokenDownTime::fromEpoch(0, ZoneInfo::fixed(19800, 'IST'));

        self::assertNotNull($time);
        self::assertSame([1970, 0, 1, 5, 30, 0, 4, 0], $time->toList());
        self::assertSame(19800, $time->gmtOffset);
        self::assertSame('IST', $time->zone);
    }

    public function testFromEpochOutOfRangeIsNull(): void
    {
        self::assertNull(BrokenDownTime::fromEpoch(\PHP_INT_MAX, ZoneInfo::utc()));
        self::assertNull(BrokenDownTime::fromEpoch(-\PHP_INT_MAX, ZoneInfo::utc()));
    }

    public function testFromJqFillsMissingElementsWithZero(): void
    {
        $time = BrokenDownTime::fromJq([2024, 2, 15]);

        self::assertNotNull($time);
        self::assertSame([2024, 2, 15, 0, 0, 0, 0, 0], $time->toList());
    }

    public function testFromJqTruncatesTowardZero(): void
    {
        $time = BrokenDownTime::fromJq([2015.9, 2.9, 5.9, 23.9, 51.9, 47.9, 4.9, 63.9]);

        self::assertNotNull($time);
        self::assertSame([2015, 2, 5, 23, 51, 47, 4, 63], $time->toList());

        $negative = BrokenDownTime::fromJq([2015, 0, 1, 0, 0, -1.5]);
        self::assertNotNull($negative);
        self::assertSame(-1, $negative->second);
    }

    public function testFromJqAcceptsPreciseNumbers(): void
    {
        $time = BrokenDownTime::fromJq([new PreciseNumber(2015.0, '2015'), 0, 1]);

        self::assertNotNull($time);
        self::assertSame(2015, $time->year);
    }

    public function testFromJqClampsToCInt(): void
    {
        $time = BrokenDownTime::fromJq([1.0E300, -1.0E300, 1, 1, 1, 1]);

        self::assertNotNull($time);
        self::assertSame(2147483647 + 1900, $time->year);
        self::assertSame(-2147483648, $time->month);
    }

    public function testFromJqTreatsNanAsZero(): void
    {
        $time = BrokenDownTime::fromJq([2000, NAN, 1]);

        self::assertNotNull($time);
        self::assertSame(0, $time->month);
    }

    public function testFromJqRejectsNonNumbers(): void
    {
        self::assertNull(BrokenDownTime::fromJq(['a', 1, 2, 3, 4, 5, 6, 7]));
        self::assertNull(BrokenDownTime::fromJq([2000, null]));
        self::assertNull(BrokenDownTime::fromJq(['2000']));
    }

    public function testWallSecondsNormalises(): void
    {
        $time = BrokenDownTime::fromJq([2015, 12, 32, 25, 61, 61]);

        self::assertNotNull($time);
        self::assertSame(Civil::timegm(2016, 1, 2, 2, 2, 1), $time->wallSeconds());
    }
}
