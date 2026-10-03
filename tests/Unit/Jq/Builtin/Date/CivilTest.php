<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use LTS\PhpXq\Jq\Builtin\Date\Civil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CivilTest extends TestCase
{
    public function testFloorDivisionAndModulo(): void
    {
        self::assertSame(2, Civil::floorDiv(7, 3));
        self::assertSame(-3, Civil::floorDiv(-7, 3));
        self::assertSame(-3, Civil::floorDiv(7, -3));
        self::assertSame(2, Civil::floorDiv(-7, -3));
        self::assertSame(-2, Civil::floorDiv(-6, 3));
        self::assertSame(1, Civil::floorMod(7, 3));
        self::assertSame(2, Civil::floorMod(-7, 3));
        self::assertSame(0, Civil::floorMod(-6, 3));
    }

    public function testLeapYears(): void
    {
        self::assertTrue(Civil::isLeapYear(2000));
        self::assertTrue(Civil::isLeapYear(2024));
        self::assertFalse(Civil::isLeapYear(1900));
        self::assertFalse(Civil::isLeapYear(2023));
    }

    #[DataProvider('dates')]
    public function testDaysAndCivilRoundTrip(int $year, int $month, int $day, int $days): void
    {
        self::assertSame($days, Civil::daysFromCivil($year, $month, $day));
        self::assertSame([$year, $month, $day], Civil::civilFromDays($days));
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function dates(): iterable
    {
        yield 'epoch' => [1970, 1, 1, 0];
        yield 'day before epoch' => [1969, 12, 31, -1];
        yield 'leap day' => [2000, 2, 29, 11016];
        yield 'y2k' => [2000, 1, 1, 10957];
        yield 'far past' => [1, 1, 1, -719162];
        yield 'far future' => [9999, 12, 31, 2932896];
        yield 'before 1900' => [1899, 12, 31, -25568];
    }

    public function testRoundTripOverTwoCenturies(): void
    {
        for ($days = -36500; $days <= 36500; ++$days) {
            [$year, $month, $day] = Civil::civilFromDays($days);
            self::assertSame($days, Civil::daysFromCivil($year, $month, $day));
        }
    }

    public function testTimegmNormalisesFields(): void
    {
        self::assertSame(0, Civil::timegm(1970, 0, 1, 0, 0, 0));
        self::assertSame(1425599507, Civil::timegm(2015, 2, 5, 23, 51, 47));
        self::assertSame(Civil::timegm(2016, 0, 1, 0, 0, 0), Civil::timegm(2015, 12, 1, 0, 0, 0));
        self::assertSame(Civil::timegm(2014, 11, 1, 0, 0, 0), Civil::timegm(2015, -1, 1, 0, 0, 0));
        self::assertSame(Civil::timegm(2015, 2, 1, 0, 0, 0), Civil::timegm(2015, 1, 29, 0, 0, 0));
        self::assertSame(-86400, Civil::timegm(1970, 0, 0, 0, 0, 0));
        self::assertSame(3600 + 61, Civil::timegm(1970, 0, 1, 0, 60, 61));
    }

    public function testDayOfYearAndDayOfWeek(): void
    {
        self::assertSame(0, Civil::dayOfYear(2015, 0, 1));
        self::assertSame(63, Civil::dayOfYear(2015, 2, 5));
        self::assertSame(64, Civil::dayOfYear(2016, 2, 5));
        self::assertSame(365, Civil::dayOfYear(2016, 11, 31));
        self::assertSame(4, Civil::dayOfWeek(2015, 2, 5));
        self::assertSame(4, Civil::dayOfWeek(1970, 0, 1));
        self::assertSame(0, Civil::dayOfWeek(2024, 8, 1));
        self::assertSame(3, Civil::dayOfWeek(1969, 11, 31));
    }

    public function testMonthAndDayFromDayOfYear(): void
    {
        self::assertSame([0, 1], Civil::monthAndDay(2015, 0));
        self::assertSame([2, 5], Civil::monthAndDay(2015, 63));
        self::assertSame([11, 31], Civil::monthAndDay(2015, 364));
        self::assertSame([1, 29], Civil::monthAndDay(2016, 59));
        self::assertSame([2, 1], Civil::monthAndDay(2016, 60));
    }
}
