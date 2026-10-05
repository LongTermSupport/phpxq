<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use LTS\PhpXq\Jq\Builtin\Date\BrokenDownTime;
use LTS\PhpXq\Jq\Builtin\Date\Strftime;
use LTS\PhpXq\Jq\Builtin\Date\ZoneInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StrftimeTest extends TestCase
{
    #[DataProvider('conversions')]
    public function testConversion(string $format, string $expected): void
    {
        self::assertSame($expected, Strftime::format($format, $this->sample()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conversions(): iterable
    {
        yield 'a' => ['%a', 'Thu'];
        yield 'A' => ['%A', 'Thursday'];
        yield 'b' => ['%b', 'Mar'];
        yield 'h' => ['%h', 'Mar'];
        yield 'B' => ['%B', 'March'];
        yield 'c' => ['%c', 'Thu Mar  5 23:51:47 2015'];
        yield 'C' => ['%C', '20'];
        yield 'd' => ['%d', '05'];
        yield 'D' => ['%D', '03/05/15'];
        yield 'e' => ['%e', ' 5'];
        yield 'F' => ['%F', '2015-03-05'];
        yield 'g' => ['%g', '15'];
        yield 'G' => ['%G', '2015'];
        yield 'H' => ['%H', '23'];
        yield 'I' => ['%I', '11'];
        yield 'j' => ['%j', '064'];
        yield 'k' => ['%k', '23'];
        yield 'l' => ['%l', '11'];
        yield 'm' => ['%m', '03'];
        yield 'M' => ['%M', '51'];
        yield 'n' => ['%n', "\n"];
        yield 'p' => ['%p', 'PM'];
        yield 'P' => ['%P', 'pm'];
        yield 'r' => ['%r', '11:51:47 PM'];
        yield 'R' => ['%R', '23:51'];
        yield 's' => ['%s', '1425599507'];
        yield 'S' => ['%S', '47'];
        yield 't' => ['%t', "\t"];
        yield 'T' => ['%T', '23:51:47'];
        yield 'u' => ['%u', '4'];
        yield 'U' => ['%U', '09'];
        yield 'V' => ['%V', '10'];
        yield 'w' => ['%w', '4'];
        yield 'W' => ['%W', '09'];
        yield 'x' => ['%x', '03/05/15'];
        yield 'X' => ['%X', '23:51:47'];
        yield 'y' => ['%y', '15'];
        yield 'Y' => ['%Y', '2015'];
        yield 'z' => ['%z', '+0000'];
        yield 'colon z is not a glibc conversion' => ['%:z', '%:z'];
        yield 'Z' => ['%Z', 'UTC'];
        yield 'percent' => ['%%', '%'];
    }

    #[DataProvider('flags')]
    public function testFlagsAndWidths(string $format, string $expected): void
    {
        self::assertSame($expected, Strftime::format($format, $this->sample()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function flags(): iterable
    {
        yield 'no padding' => ['%-d', '5'];
        yield 'space padding' => ['%_m', ' 3'];
        yield 'zero padding of a blank padded field' => ['%0e', '05'];
        yield 'upper case' => ['%^a', 'THU'];
        yield 'upper case month' => ['%^B', 'MARCH'];
        yield 'swap case' => ['%#p', 'pm'];
        yield 'swap case zone' => ['%#Z', 'utc'];
        yield 'swap case day' => ['%#a', 'THU'];
        yield 'width' => ['%10A', '  Thursday'];
        yield 'zero width' => ['%010d', '0000000005'];
        yield 'numeric width' => ['%5d', '00005'];
        yield 'space width' => ['%_5d', '    5'];
        yield 'composite width' => ['%12T', '    23:51:47'];
        yield 'E modifier' => ['%Ey', '15'];
        yield 'O modifier' => ['%Od', '05'];
        yield 'literal text' => ['at %H hours', 'at 23 hours'];
        yield 'unknown conversion' => ['%Q', '%Q'];
        yield 'trailing percent' => ['abc%', 'abc%'];
        yield 'empty' => ['', ''];
    }

    public function testTwelveHourClockEdges(): void
    {
        self::assertSame('12 AM', Strftime::format('%I %p', new BrokenDownTime(2020, 0, 1, 0, 0, 0, 3, 0)));
        self::assertSame('12 PM', Strftime::format('%I %p', new BrokenDownTime(2020, 0, 1, 12, 0, 0, 3, 0)));
        self::assertSame(' 1 PM', Strftime::format('%l %p', new BrokenDownTime(2020, 0, 1, 13, 0, 0, 3, 0)));
    }

    public function testZoneFieldsComeFromTheTime(): void
    {
        $time = new BrokenDownTime(2025, 5, 21, 12, 0, 0, 6, 171, 7200, 'CEST');

        self::assertSame('+0200 CEST', Strftime::format('%z %Z', $time));
        self::assertSame('1750500000', Strftime::format('%s', $time));
        self::assertSame('-0330', Strftime::format('%z', new BrokenDownTime(2025, 0, 1, 0, 0, 0, 3, 0, -12600, 'NST')));
        self::assertSame('+0530', Strftime::format('%z', new BrokenDownTime(2025, 0, 1, 0, 0, 0, 3, 0, 19815, 'X')));
    }

    public function testIsoWeekAtYearBoundaries(): void
    {
        // 2016-01-01 is a Friday belonging to ISO week 53 of 2015
        $friday = new BrokenDownTime(2016, 0, 1, 0, 0, 0, 5, 0);
        self::assertSame('2015-W53', Strftime::format('%G-W%V', $friday));
        self::assertSame('15', Strftime::format('%g', $friday));

        // 2018-12-31 is a Monday belonging to ISO week 1 of 2019
        $monday = new BrokenDownTime(2018, 11, 31, 0, 0, 0, 1, 364);
        self::assertSame('2019-W01', Strftime::format('%G-W%V', $monday));

        // 2020-12-31 is a Thursday in ISO week 53 of 2020
        $thursday = new BrokenDownTime(2020, 11, 31, 0, 0, 0, 4, 365);
        self::assertSame('2020-W53', Strftime::format('%G-W%V', $thursday));
    }

    public function testWeekNumbersFromTheArrayFields(): void
    {
        // Sunday 2017-01-01: %U starts week 1 on it, %W counts it in week 0, ISO week 52 of 2016
        $sunday = new BrokenDownTime(2017, 0, 1, 0, 0, 0, 0, 0);

        self::assertSame('01', Strftime::format('%U', $sunday));
        self::assertSame('00', Strftime::format('%W', $sunday));
        self::assertSame('7', Strftime::format('%u', $sunday));
        self::assertSame('2016-W52', Strftime::format('%G-W%V', $sunday));
    }

    public function testNegativeYear(): void
    {
        self::assertSame('-5', Strftime::format('%Y', new BrokenDownTime(-5, 0, 1, 0, 0, 0, 0, 0)));
        self::assertSame('-1', Strftime::format('%C', new BrokenDownTime(-5, 0, 1, 0, 0, 0, 0, 0)));
        self::assertSame('95', Strftime::format('%y', new BrokenDownTime(-5, 0, 1, 0, 0, 0, 0, 0)));
    }

    public function testFieldsOutOfRangeDoNotCrash(): void
    {
        $odd = new BrokenDownTime(2020, 15, 1, 0, 0, 0, 9, 0);

        self::assertSame('Tue Apr', Strftime::format('%a %b', $odd));
    }

    public function testFormatIsUsedWithAZoneInfoFixedOffset(): void
    {
        $time = BrokenDownTime::fromEpoch(0, ZoneInfo::fixed(-3600, 'WAT'));

        self::assertNotNull($time);
        self::assertSame('1969-12-31 23:00:00 -0100 WAT', Strftime::format('%F %T %z %Z', $time));
    }

    /**
     * 2015-03-05 23:51:47 UTC, a Thursday, day 63 of the year.
     */
    private function sample(): BrokenDownTime
    {
        return new BrokenDownTime(2015, 2, 5, 23, 51, 47, 4, 63);
    }
}
