<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use LTS\PhpXq\Jq\Builtin\Date\Strptime;
use LTS\PhpXq\Jq\Builtin\Date\ZoneInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StrptimeTest extends TestCase
{
    /**
     * @param list<int> $expected
     */
    #[DataProvider('parses')]
    public function testParse(string $input, string $format, array $expected): void
    {
        $parsed = Strptime::parse($input, $format, ZoneInfo::utc());

        self::assertNotNull($parsed, $input);
        self::assertSame($expected, $parsed[0]);
        self::assertSame('', $parsed[1]);
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function parses(): iterable
    {
        yield 'iso' => ['2015-03-05T23:51:47Z', '%Y-%m-%dT%H:%M:%SZ', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'F and T' => ['2015-03-05 23:51:47', '%F %T', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'names' => ['Thu, 05 Mar 2015', '%a, %d %b %Y', [2015, 2, 5, 0, 0, 0, 4, 63]];
        yield 'full names' => ['Thursday, 5 March 2015', '%A, %e %B %Y', [2015, 2, 5, 0, 0, 0, 4, 63]];
        yield 'case insensitive names' => ['tHURSDAY mARCH', '%A %B', [1900, 2, 0, 0, 0, 0, 4, 58]];
        yield 'two digit year 69 to 99' => ['70-01-01', '%y-%m-%d', [1970, 0, 1, 0, 0, 0, 4, 0]];
        yield 'two digit year 00 to 68' => ['68-01-01', '%y-%m-%d', [2068, 0, 1, 0, 0, 0, 0, 0]];
        yield 'century and year' => ['20 15-03-05', '%C %y-%m-%d', [2015, 2, 5, 0, 0, 0, 4, 63]];
        yield 'twelve hour pm' => ['11:51:47 PM', '%I:%M:%S %p', [1900, 0, 0, 23, 51, 47, 8, 367]];
        yield 'twelve hour am noon edge' => ['12:00 AM', '%I:%M %p', [1900, 0, 0, 0, 0, 0, 8, 367]];
        yield 'twelve hour pm noon edge' => ['12:00 PM', '%I:%M %p', [1900, 0, 0, 12, 0, 0, 8, 367]];
        yield 'r' => ['11:51:47 PM', '%r', [1900, 0, 0, 23, 51, 47, 8, 367]];
        yield 'R' => ['23:51', '%R', [1900, 0, 0, 23, 51, 0, 8, 367]];
        yield 'D' => ['03/05/15', '%D', [2015, 2, 5, 0, 0, 0, 4, 63]];
        yield 'x and X' => ['03/05/15 23:51:47', '%x %X', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'c' => ['Thu Mar  5 23:51:47 2015', '%c', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'day of year' => ['2015 064', '%Y %j', [2015, 2, 5, 0, 0, 0, 4, 63]];
        yield 'leap day of year' => ['2016 060', '%Y %j', [2016, 1, 29, 0, 0, 0, 1, 59]];
        yield 'k and l' => [' 7 8', '%k %l', [1900, 0, 0, 8, 0, 0, 8, 367]];
        yield 'timezone name is swallowed' => ['2015-03-05T23:51:47 CEST', '%Y-%m-%dT%H:%M:%S %Z', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'offset Z' => ['2015-03-05T23:51:47Z', '%Y-%m-%dT%H:%M:%S%z', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'offset hhmm' => ['2015-03-05 23:51:47 +0530', '%F %T %z', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'offset hh:mm' => ['2015-03-05 23:51:47 -05:30', '%F %T %z', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'offset hh' => ['2015-03-05 23:51:47 +05', '%F %T %z', [2015, 2, 5, 23, 51, 47, 4, 63]];
        yield 'percent literal' => ['100%', '100%%', [1900, 0, 0, 0, 0, 0, 8, 367]];
        yield 'whitespace matches any run' => ["2015 \t  03", '%Y %m', [2015, 2, 0, 0, 0, 0, 6, 58]];
        yield 'n and t' => ["2015\n\t03", '%Y%n%t%m', [2015, 2, 0, 0, 0, 0, 6, 58]];
        yield 'flags and widths are ignored' => ['2015-3-5', '%-Y-%_m-%0d', [2015, 2, 5, 0, 0, 0, 4, 63]];
        yield 'E modifier' => ['2015', '%EY', [2015, 0, 0, 0, 0, 0, 3, -1]];
        yield 'weekday number u' => ['7', '%u', [1900, 0, 0, 0, 0, 0, 0, 367]];
        yield 'weekday number w' => ['3', '%w', [1900, 0, 0, 0, 0, 0, 3, 367]];
        yield 'ignored fields' => ['15 2015 10', '%g %G %V', [1900, 0, 0, 0, 0, 0, 8, 367]];
        yield 'leading space in numbers' => ['  5', '%d', [1900, 0, 5, 0, 0, 0, 5, 4]];
        yield 'unix epoch in utc' => ['1425599507', '%s', [2015, 2, 5, 23, 51, 47, 4, 63]];
    }

    public function testUnixEpochUsesTheGivenZone(): void
    {
        $parsed = Strptime::parse('0', '%s', ZoneInfo::fixed(3600, 'CET'));

        self::assertNotNull($parsed);
        self::assertSame([1970, 0, 1, 1, 0, 0, 4, 0], $parsed[0]);
    }

    public function testWeekNumbers(): void
    {
        // Sunday-based week 9 of 2015, Thursday: 2015-03-05
        $sunday = Strptime::parse('2015 09 4', '%Y %U %w', ZoneInfo::utc());
        self::assertNotNull($sunday);
        self::assertSame([2015, 2, 5, 0, 0, 0, 4, 63], $sunday[0]);

        // Monday-based week 9 of 2015, Thursday
        $monday = Strptime::parse('2015 09 4', '%Y %W %w', ZoneInfo::utc());
        self::assertNotNull($monday);
        self::assertSame([2015, 2, 5, 0, 0, 0, 4, 63], $monday[0]);
    }

    public function testTrailingTextAfterWhitespaceIsReturned(): void
    {
        $parsed = Strptime::parse('2015-03-05 extra words', '%Y-%m-%d', ZoneInfo::utc());

        self::assertNotNull($parsed);
        self::assertSame(' extra words', $parsed[1]);
    }

    public function testTrailingTextWithoutWhitespaceFails(): void
    {
        self::assertNull(Strptime::parse('2015-03-05x', '%Y-%m-%d', ZoneInfo::utc()));
    }

    #[DataProvider('failures')]
    public function testMismatchReturnsNull(string $input, string $format): void
    {
        self::assertNull(Strptime::parse($input, $format, ZoneInfo::utc()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'empty input' => ['', '%Y'];
        yield 'not a number' => ['abc', '%Y'];
        yield 'month out of range' => ['13', '%m'];
        yield 'month zero' => ['0', '%m'];
        yield 'day out of range' => ['32', '%d'];
        yield 'hour out of range' => ['24', '%H'];
        yield 'twelve hour zero' => ['0', '%I'];
        yield 'minute out of range' => ['60', '%M'];
        yield 'second out of range' => ['62', '%S'];
        yield 'day of year out of range' => ['367', '%j'];
        yield 'literal mismatch' => ['2015/03', '%Y-%m'];
        yield 'missing literal' => ['2015', '%Y-'];
        yield 'bad weekday name' => ['Funday', '%A'];
        yield 'bad month name' => ['Smarch', '%B'];
        yield 'bad meridian' => ['XM', '%p'];
        yield 'percent mismatch' => ['x', '%%'];
        yield 'unknown conversion' => ['x', '%Q'];
        yield 'trailing percent' => ['x', '%'];
        yield 'offset without sign' => ['0100', '%z'];
        yield 'offset with three digits' => ['+010', '%z'];
        yield 'offset minutes out of range' => ['+0160', '%z'];
        yield 'epoch without digits' => ['x', '%s'];
        yield 'epoch out of range' => ['99999999999999999', '%s'];
    }

    public function testEarlyNumberStopRespectsTheUpperBound(): void
    {
        // %d reads "3" then sees "5": 35 is out of range, so the conversion fails like glibc
        self::assertNull(Strptime::parse('35', '%d', ZoneInfo::utc()));

        // %m reads "1" then "2"
        $parsed = Strptime::parse('12', '%m', ZoneInfo::utc());
        self::assertNotNull($parsed);
        self::assertSame(11, $parsed[0][1]);

        // a 2 digit field leaves the third digit for the next conversion
        $split = Strptime::parse('0112', '%m%d', ZoneInfo::utc());
        self::assertNotNull($split);
        self::assertSame([1900, 0, 12, 0, 0, 0, 5, 11], $split[0]);
    }

    public function testDayWithoutAMonthStillGetsWeekdayAndYearDay(): void
    {
        $parsed = Strptime::parse('31', '%d', ZoneInfo::utc());

        self::assertNotNull($parsed);
        self::assertSame([1900, 0, 31, 0, 0, 0, 3, 30], $parsed[0]);
    }
}
