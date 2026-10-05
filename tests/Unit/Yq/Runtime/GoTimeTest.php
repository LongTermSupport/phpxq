<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use LTS\PhpXq\Yq\Runtime\GoTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tables are one row per line, fields separated by a pipe; a tilde stands for null.
 *
 * @internal
 */
#[CoversClass(GoTime::class)]
final class GoTimeTest extends TestCase
{
    private const string UTC = 'UTC';

    private const string STAMP ='Y-m-d\TH:i:s.uP';

    #[DataProvider('formatCases')]
    public function testFormatRendersGoLayouts(string $zone, string $layout, string $expected): void
    {
        $time = new DateTimeImmutable('2009-11-05 15:04:05.123456', new DateTimeZone($zone));

        self::assertSame($expected, GoTime::format($time, $layout));
    }

    /**
     * @return Generator<string, list<string>> zone, layout, expected
     */
    public static function formatCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            UTC|2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05Z
            Asia/Kolkata|2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05+05:30
            UTC|Mon Jan _2 15:04:05 2006|Thu Nov  5 15:04:05 2009
            UTC|Monday, 02-Jan-06 at 3:04PM MST|Thursday, 05-Nov-09 at 3:04PM UTC
            UTC|03:04:05pm|03:04:05pm
            UTC|3:4:5|3:4:5
            UTC|03:04:05|03:04:05
            UTC|1/2/06|11/5/09
            UTC|01/02/2006|11/05/2009
            UTC|002|309
            UTC|2006-002|2009-309
            UTC|_2| 5
            UTC|_2006|_2009
            UTC|_x|_x
            UTC|10|110
            UTC|-08|-08
            UTC|007|007
            UTC|06|09
            UTC|01|11
            UTC|05|05
            UTC|07|07
            UTC|Jx|Jx
            UTC|Ja|Ja
            UTC|Jan|Nov
            UTC|January|November
            UTC|Januaryx|Novemberx
            UTC|Mx|Mx
            UTC|Mo|Mo
            UTC|Mon|Thu
            UTC|Monday|Thursday
            UTC|Monda|Thuda
            UTC|MST|UTC
            Asia/Kolkata|MST|IST
            America/New_York|MST|EST
            UTC|Px|Px
            UTC|px|px
            UTC|PM|PM
            UTC|pm|pm
            Asia/Kolkata|-0700|+0530
            Asia/Kolkata|-07:00|+05:30
            Asia/Kolkata|-07|+05
            Asia/Kolkata|-070000|+053000
            Asia/Kolkata|-07:00:00|+05:30:00
            America/New_York|-07:00|-05:00
            Asia/Kolkata|Z0700|+0530
            Asia/Kolkata|Z07:00|+05:30
            Asia/Kolkata|Z07|+05
            Asia/Kolkata|Z070000|+053000
            Asia/Kolkata|Z07:00:00|+05:30:00
            UTC|Z0700|Z
            UTC|Z07:00|Z
            UTC|Z07|Z
            UTC|Z070000|Z
            UTC|Z07:00:00|Z
            UTC|Zx|Zx
            UTC|-x|-x
            UTC|05.000|05.123
            UTC|05.000000|05.123456
            UTC|05.000000000|05.123456000
            UTC|05.0|05.1
            UTC|05,000|05,123
            UTC|05.999999|05.123456
            UTC|05.99|05.12
            UTC|.999|.123
            UTC|.000x|.123x
            UTC|.01|.11
            UTC|.0001|.0011
            UTC|a.b|a.b
            UTC|.x|.x
            UTC|.95|.95
            UTC|,999x|,123x
            UTC|plain text|plain text
            UTC||
            UTC|15|15
            UTC|2006|2009
            UTC|2|5
            UTC|4-5|4-5
            TABLE);
    }

    #[DataProvider('zoneNameCases')]
    public function testFormatZoneNameOfOffsetZones(string $text, string $expected): void
    {
        self::assertSame($expected, GoTime::format(new DateTimeImmutable($text), 'MST'));
    }

    /**
     * @return Generator<string, list<string>> datetime text, expected zone name
     */
    public static function zoneNameCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            2009-11-05T15:04:05+02:00|+02
            2009-11-05T15:04:05-03:30|-03
            2009-11-05T15:04:05+00:00|UTC
            2009-11-05T15:04:05Z|UTC
            2009-11-05T15:04:05 UTC|UTC
            2009-11-05T15:04:05 Europe/Paris|CET
            TABLE);
    }

    public function testFormatTwelveHourClockAndZeroPaddedMinutes(): void
    {
        $time = new DateTimeImmutable('2009-11-05 00:07:09.5', new DateTimeZone(self::UTC));

        self::assertSame('12:07:09 AM', GoTime::format($time, '03:04:05 PM'));
        self::assertSame('12:7:9 am', GoTime::format($time, '3:4:5 pm'));
        self::assertSame('.500', GoTime::format($time, '.000'));
        self::assertSame('.5', GoTime::format($time, '.999'));

        $whole = new DateTimeImmutable('2009-11-05 13:00:00', new DateTimeZone(self::UTC));
        self::assertSame('', GoTime::format($whole, '.999'));
        self::assertSame('01:00:00 PM', GoTime::format($whole, '03:04:05 PM'));
    }

    public function testFormatDayOfYearIsPaddedToThreeDigits(): void
    {
        $time = new DateTimeImmutable('2009-01-05', new DateTimeZone(self::UTC));

        self::assertSame('005', GoTime::format($time, '002'));
        self::assertSame(' 5', GoTime::format($time, '_2'));
    }

    public function testFormatTwoDigitDayAndSingleDigitDay(): void
    {
        $time = new DateTimeImmutable('2009-01-25', new DateTimeZone(self::UTC));

        self::assertSame('25', GoTime::format($time, '_2'));
        self::assertSame('25/1', GoTime::format($time, '2/1'));
    }

    public function testFormatSurvivesLayoutCacheRollover(): void
    {
        $time = new DateTimeImmutable('2009-11-05 15:04:05', new DateTimeZone(self::UTC));

        for ($i = 1; $i <= 300; ++$i) {
            $layout = '2006 ' . str_repeat('q', $i);

            self::assertSame('2009 ' . str_repeat('q', $i), GoTime::format($time, $layout));
        }

        self::assertSame('2009-11-05', GoTime::format($time, '2006-01-02'));
        self::assertSame('2009 qqq', GoTime::format($time, '2006 qqq'));
    }

    #[DataProvider('parseCases')]
    public function testParseReadsGoLayouts(string $layout, string $value, string $expected): void
    {
        $time = GoTime::parse($layout, $value);

        if ('~' === $expected) {
            self::assertNotInstanceOf(DateTimeImmutable::class, $time);

            return;
        }

        self::assertInstanceOf(DateTimeImmutable::class, $time);
        self::assertSame($expected, $time->format(self::STAMP));
    }

    /**
     * @return Generator<string, list<string>> layout, value, expected (tilde when no match)
     */
    public static function parseCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05Z|2009-11-05T15:04:05.000000+00:00
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05+02:00|2009-11-05T15:04:05.000000+02:00
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05-08:30|2009-11-05T15:04:05.000000-08:30
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05+00:00|2009-11-05T15:04:05.000000+00:00
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05.25Z|2009-11-05T15:04:05.250000+00:00
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05|~
            2006-01-02T15:04:05Z07:00|2009-11-05T15:04:05Zx|~
            2006-01-02|2009-11-05|2009-11-05T00:00:00.000000+00:00
            2006-01-02|2009/11/05|~
            2006-01-02|209-11-05|~
            2006-01-02|2009-1-05|~
            2006-01-02|2009-11-5|~
            2006-01-02||~
            |x|~
            ||~
            2006-01-02|2009-00-05|~
            2006-01-02|2009-13-05|~
            2006-01-02|2009-12-05|2009-12-05T00:00:00.000000+00:00
            2006-01-02|2009-01-05|2009-01-05T00:00:00.000000+00:00
            2006-01-02|2009-11-00|~
            2006-01-02|2009-11-32|~
            2006-01-02|2009-12-31|2009-12-31T00:00:00.000000+00:00
            2006-01-02|2009-11-31|~
            2006-01-02|2008-02-29|2008-02-29T00:00:00.000000+00:00
            2006-01-02|2009-02-29|~
            2006-01-02|0000-02-30|0000-03-01T00:00:00.000000+00:00
            2006-01-02|0001-02-30|~
            01-02|11-05|0000-11-05T00:00:00.000000+00:00
            06-01-02|69-01-02|1969-01-02T00:00:00.000000+00:00
            06-01-02|68-01-02|2068-01-02T00:00:00.000000+00:00
            06-01-02|00-01-02|2000-01-02T00:00:00.000000+00:00
            06-01-02|99-01-02|1999-01-02T00:00:00.000000+00:00
            06-01-02|9-01-02|~
            1/2/2006|1/2/2006|2006-01-02T00:00:00.000000+00:00
            1/2/2006|12/31/2006|2006-12-31T00:00:00.000000+00:00
            1/2/2006|123/1/2006|~
            1/2/2006|1/123/2006|~
            1/2/2006|/2/2006|~
            1/2/2006|1//2006|~
            Jan 2 2006|Mar 5 2020|2020-03-05T00:00:00.000000+00:00
            Jan 2 2006|dec 25 2020|2020-12-25T00:00:00.000000+00:00
            Jan 2 2006|Jan 5 2020|2020-01-05T00:00:00.000000+00:00
            Jan 2 2006|Foo 5 2020|~
            Jan 2 2006|March 5 2020|~
            January 2 2006|September 5 2020|2020-09-05T00:00:00.000000+00:00
            January 2 2006|Mayx 5 2020|~
            January 2 2006|Mar 5 2020|~
            Jan _2 2006|Jan  5 2006|2006-01-05T00:00:00.000000+00:00
            Jan _2 2006|Jan 15 2006|2006-01-15T00:00:00.000000+00:00
            _2/1/2006| 5/1/2006|2006-01-05T00:00:00.000000+00:00
            _2/1/2006| /1/2006|~
            2006-002|2009-015|2009-01-15T00:00:00.000000+00:00
            2006-002|2009-001|2009-01-01T00:00:00.000000+00:00
            2006-002|2009-15|~
            2006-002|2009-000|~
            2006-002|2009-031|2009-01-31T00:00:00.000000+00:00
            2006-002|2009-032|~
            15:04:05|23:59:59|0000-01-01T23:59:59.000000+00:00
            15:04:05|24:00:00|~
            15:04:05|12:60:00|~
            15:04:05|12:00:60|~
            03:04:05|07:08:09|0000-01-01T07:08:09.000000+00:00
            03:04:05|7:08:09|~
            3:04:05|7:08:09|0000-01-01T07:08:09.000000+00:00
            3:04:05|17:08:09|0000-01-01T17:08:09.000000+00:00
            3:04:05|7:8:09|~
            3:04:05|7:08:9|~
            3:4:5|7:8:9|0000-01-01T07:08:09.000000+00:00
            3:4:5|7:18:19|0000-01-01T07:18:19.000000+00:00
            3:4:5|7::9|~
            3:4:5|7:8:|~
            3:04PM|3:04PM|0000-01-01T15:04:00.000000+00:00
            3:04PM|3:04pm|0000-01-01T15:04:00.000000+00:00
            3:04PM|3:04AM|0000-01-01T03:04:00.000000+00:00
            3:04PM|12:30PM|0000-01-01T12:30:00.000000+00:00
            3:04PM|12:30AM|0000-01-01T00:30:00.000000+00:00
            3:04PM|11:00PM|0000-01-01T23:00:00.000000+00:00
            3:04PM|11:00AM|0000-01-01T11:00:00.000000+00:00
            3:04PM|13:00PM|0000-01-01T13:00:00.000000+00:00
            3:04PM|3:04XM|~
            3:04PM|3:04|~
            3:04PM|3:04P|~
            3:04pm|3:04PM|0000-01-01T15:04:00.000000+00:00
            3:04pm|3:04am|0000-01-01T03:04:00.000000+00:00
            15:04:05.000|10:20:30.123|0000-01-01T10:20:30.123000+00:00
            15:04:05,000|10:20:30,123|0000-01-01T10:20:30.123000+00:00
            15:04:05,000|10:20:30.123|0000-01-01T10:20:30.123000+00:00
            15:04:05.000|10:20:30,123|~
            15:04:05.000|10:20:30|~
            15:04:05.000|10:20:30x123|~
            15:04:05.999999999|10:20:30.123456789|0000-01-01T10:20:30.123456+00:00
            15:04:05|10:20:30.5|0000-01-01T10:20:30.500000+00:00
            15:04:05|10:20:30.123456789|0000-01-01T10:20:30.123456+00:00
            15:04:05|10:20:30.|~
            15:04:05|10:20:30.x|~
            3:4:5|1:2:3.5|0000-01-01T01:02:03.500000+00:00
            15:04:05.000 MST|10:20:30.500 UTC|0000-01-01T10:20:30.500000+00:00
            15:04:05.000x|10:20:30.5x|0000-01-01T10:20:30.500000+00:00
            15:04:05x|10:20:30.512345x|0000-01-01T10:20:30.512345+00:00
            15:04:05x|10:20:30.5123x|0000-01-01T10:20:30.512300+00:00
            Mon Jan 2 2006|Thu Nov 5 2009|2009-11-05T00:00:00.000000+00:00
            Monday, Jan 2 2006|Thursday, Nov 5 2009|2009-11-05T00:00:00.000000+00:00
            Mon Jan 2 2006|Th Nov 5 2009|~
            Mon Jan 2 2006|Abc Nov 5 2009|2009-11-05T00:00:00.000000+00:00
            Mon Jan 2 2006| Nov 5 2009|~
            Mon|Thu|0000-01-01T00:00:00.000000+00:00
            Mon|Th|~
            15:04 MST|10:20 UTC|0000-01-01T10:20:00.000000+00:00
            15:04 MST|10:20 EST|0000-01-01T10:20:00.000000-05:00
            15:04 MST|10:20 +0530|0000-01-01T10:20:00.000000+05:30
            15:04 MST|10:20 -07|0000-01-01T10:20:00.000000-07:00
            15:04 MST|10:20 ZZZ|0000-01-01T10:20:00.000000+00:00
            15:04 MST|10:20 AB|~
            15:04 MST|10:20 ABCDEF|~
            15:04 MST|10:20 ABCDE|0000-01-01T10:20:00.000000+00:00
            15:04 MST|10:20 utc|~
            15:04 -0700|10:20 +0530|0000-01-01T10:20:00.000000+05:30
            15:04 -0700|10:20 -0830|0000-01-01T10:20:00.000000-08:30
            15:04 -0700|10:20 +0000|0000-01-01T10:20:00.000000+00:00
            15:04 -0700|10:20 +05:30|~
            15:04 -0700|10:20 0530|~
            15:04 -0700|10:20 Z|~
            15:04 -07:00|10:20 -08:30|0000-01-01T10:20:00.000000-08:30
            15:04 -07:00|10:20 -0830|~
            15:04 -07|10:20 +05|0000-01-01T10:20:00.000000+05:00
            15:04 -07|10:20 +5|~
            15:04 -070000|10:20 +053015|0000-01-01T10:20:00.000000+05:30
            15:04 -070000|10:20 +0530|~
            15:04 -07:00:00|10:20 -08:30:45|0000-01-01T10:20:00.000000-08:30
            15:04 -07:00:00|10:20 -08:30|~
            15:04 -07:00:00|10:20 +00:00:30|0000-01-01T10:20:00.000000+00:00
            15:04Z0700|10:20Z|0000-01-01T10:20:00.000000+00:00
            15:04Z0700|10:20+0100|0000-01-01T10:20:00.000000+01:00
            15:04Z0700|10:20|~
            15:04Z07:00|10:20Z|0000-01-01T10:20:00.000000+00:00
            15:04Z07:00|10:20-01:30|0000-01-01T10:20:00.000000-01:30
            15:04Z07|10:20Z|0000-01-01T10:20:00.000000+00:00
            15:04Z07|10:20+03|0000-01-01T10:20:00.000000+03:00
            15:04Z070000|10:20Z|0000-01-01T10:20:00.000000+00:00
            15:04Z070000|10:20+010203|0000-01-01T10:20:00.000000+01:02
            15:04Z07:00:00|10:20Z|0000-01-01T10:20:00.000000+00:00
            15:04Z07:00:00|10:20+01:02:03|0000-01-01T10:20:00.000000+01:02
            15|1|~
            2006|20|~
            TABLE);
    }

    #[DataProvider('timestampCases')]
    public function testLooksLikeTimestamp(string $text, string $expected): void
    {
        self::assertSame('1' === $expected, GoTime::looksLikeTimestamp($text));
    }

    /**
     * @return Generator<string, list<string>> text, 1 for a timestamp and 0 otherwise
     */
    public static function timestampCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            2009-11-05|1
            2009-1-5|1
            2009-11-05T10:20:30|1
            2009-11-05t10:20:30|1
            2009-11-05 10:20:30|1
            2009-11-05   10:20:30|1
            2009-11-05T1:20:30|1
            2009-11-05T10:20:30.123|1
            2009-11-05T10:20:30.|1
            2009-11-05T10:20:30Z|1
            2009-11-05T10:20:30 Z|1
            2009-11-05T10:20:30+02:00|1
            2009-11-05T10:20:30-2|1
            2009-11-05T10:20:30+0200|0
            2009-11-05 10:20:30 +02:00|1
            2009-11-05x|0
            x2009-11-05|0
            209-11-05|0
            20009-11-05|0
            2009-11-05T10:20|0
            2009-11-05T10:2:30|0
            2009-111-05|0
            2009-11-055|0
            2009-11-05T100:20:30|0
            hello|0
            |0
            TABLE);
    }

    public function testLooksLikeTimestampWithTabSeparatorAndTrailingNewline(): void
    {
        self::assertTrue(GoTime::looksLikeTimestamp("2009-11-05\t10:20:30"));
        self::assertTrue(GoTime::looksLikeTimestamp("2009-11-05 10:20:30\t+02:00"));
        self::assertFalse(GoTime::looksLikeTimestamp("2009-11-05\n"));
        self::assertFalse(GoTime::looksLikeTimestamp("2009-11-05T10:20:30Z\n"));
    }

    #[DataProvider('tryParseCases')]
    public function testTryParse(string $value, string $layout, string $expected): void
    {
        $time = GoTime::tryParse($value, '~' === $layout ? null : $layout);

        if ('~' === $expected) {
            self::assertNotInstanceOf(DateTimeImmutable::class, $time);

            return;
        }

        self::assertInstanceOf(DateTimeImmutable::class, $time);
        self::assertSame($expected, $time->format(self::STAMP));
    }

    /**
     * @return Generator<string, list<string>> value, layout (tilde for none), expected (tilde when no match)
     */
    public static function tryParseCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            2009-11-05T10:20:30Z|~|2009-11-05T10:20:30.000000+00:00
            2009-11-05T10:20:30+02:00|~|2009-11-05T10:20:30.000000+02:00
            2009-11-05T10:20:30|~|2009-11-05T10:20:30.000000+00:00
            2009-11-05 10:20:30+02:00|~|2009-11-05T10:20:30.000000+02:00
            2009-11-05 10:20:30|~|2009-11-05T10:20:30.000000+00:00
            2009-11-05|~|2009-11-05T00:00:00.000000+00:00
            2009-11-05 garbage|~|~
            2009-1-05|~|~
            x2009-11-05|~|~
            hello|~|~
            |~|~
            20009-11-05|~|~
            05/11/2009|02/01/2006|2009-11-05T00:00:00.000000+00:00
            10:20|15:04|0000-01-01T10:20:00.000000+00:00
            2009-11-05|02/01/2006|~
            TABLE);
    }

    #[DataProvider('durationCases')]
    public function testParseDuration(string $text, string $expected): void
    {
        self::assertSame('~' === $expected ? null : (int)$expected, GoTime::parseDuration($text));
    }

    /**
     * @return Generator<string, list<string>> text, nanoseconds (tilde when not a duration)
     */
    public static function durationCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            1h30m|5400000000000
            1.5h|5400000000000
            -1.5h|-5400000000000
            +3s|3000000000
            0|0
            -0|0
            +0|0
            0s|0
            |~
            -|~
            +|~
            1|~
            -5|~
            s|~
            .s|~
            1.s|1000000000
            .5s|500000000
            1ns|1
            1us|1000
            1µs|1000
            1μs|1000
            1ms|1000000
            1s|1000000000
            1m|60000000000
            1h|3600000000000
            300ms|300000000
            1h1h|7200000000000
            1h2m3s4ms5us6ns|3723004005006
            1x|~
            1s2|~
            1h5|~
            0.0000000006s|1
            0.0000000004s|0
            1.5ns|2
            -1.5ns|-2
            -3m|-180000000000
            1H|~
            --1s|~
            100h|360000000000000
            007s|7000000000
            TABLE);
    }

    public function testParseDurationRejectsSurroundingWhitespace(): void
    {
        self::assertNotInstanceOf(DateTimeImmutable::class, GoTime::parse('15:04', ' 10:20'));
        self::assertNull(GoTime::parseDuration(' 1h'));
        self::assertNull(GoTime::parseDuration("1h\n"));
        self::assertNull(GoTime::parseDuration('1h '));
    }

    #[DataProvider('addNanosCases')]
    public function testAddNanos(string $base, string $nanos, string $expected): void
    {
        $time = new DateTimeImmutable($base, new DateTimeZone('+02:00'));

        self::assertSame($expected, GoTime::addNanos($time, (int)$nanos)->format(self::STAMP));
    }

    /**
     * @return Generator<string, list<string>> base, nanoseconds, expected
     */
    public static function addNanosCases(): Generator
    {
        yield from self::rows(<<<'TABLE'
            2009-11-05 15:04:05.250000|0|2009-11-05T15:04:05.250000+02:00
            2009-11-05 15:04:05.250000|1000000000|2009-11-05T15:04:06.250000+02:00
            2009-11-05 15:04:05.250000|750000000|2009-11-05T15:04:06.000000+02:00
            2009-11-05 15:04:05.250000|749999000|2009-11-05T15:04:05.999999+02:00
            2009-11-05 15:04:05.250000|100000000|2009-11-05T15:04:05.350000+02:00
            2009-11-05 15:04:05.250000|-250000000|2009-11-05T15:04:05.000000+02:00
            2009-11-05 15:04:05.250000|-300000000|2009-11-05T15:04:04.950000+02:00
            2009-11-05 15:04:05.250000|-250001000|2009-11-05T15:04:04.999999+02:00
            2009-11-05 15:04:05.250000|-1000000000|2009-11-05T15:04:04.250000+02:00
            2009-11-05 15:04:05.250000|1999|2009-11-05T15:04:05.250001+02:00
            2009-11-05 15:04:05.250000|1000|2009-11-05T15:04:05.250001+02:00
            2009-11-05 15:04:05.250000|999|2009-11-05T15:04:05.250000+02:00
            2009-11-05 15:04:05.250000|3600000000000|2009-11-05T16:04:05.250000+02:00
            2009-11-05 15:04:05.250000|-3601500000000|2009-11-05T14:04:03.750000+02:00
            2009-11-05 15:04:05.250000|2500000000|2009-11-05T15:04:07.750000+02:00
            2009-11-05 15:04:05.250000|-2500000000|2009-11-05T15:04:02.750000+02:00
            2009-11-05 23:59:59.900000|100000000|2009-11-06T00:00:00.000000+02:00
            2009-11-05 00:00:00.100000|-200000000|2009-11-04T23:59:59.900000+02:00
            TABLE);
    }

    /**
     * @return Generator<string, list<string>>
     */
    private static function rows(string $table): Generator
    {
        foreach (explode("\n", trim($table)) as $line) {
            yield $line => explode('|', $line);
        }
    }
}
