<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use LTS\PhpXq\Jq\Builtin\Date\BrokenDownTime;
use LTS\PhpXq\Jq\Builtin\Date\Strftime;
use LTS\PhpXq\Jq\Builtin\Date\ZoneInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * strftime across ISO week, week number and century boundaries and for values narrower than a field's default
 * width; every expectation is what jq prints for the same instant.
 *
 * @internal
 */
final class StrftimeBoundariesTest extends TestCase
{
    private const string FIELDS = '%G|%g|%V|%U|%W|%j|%u|%w|%C|%y|%Y|%I|%k|%l|%s|%e';

    #[DataProvider('instants')]
    public function testFieldsOfAnInstant(int $epoch, string $expected): void
    {
        $time = BrokenDownTime::fromEpoch($epoch, ZoneInfo::utc());

        self::assertInstanceOf(BrokenDownTime::class, $time);
        self::assertSame($expected, Strftime::format(self::FIELDS, $time));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function instants(): iterable
    {
        $rows = [
            '2014-12-28 Sunday of week 52'        => [1419743229, '2014|14|52|52|51|362|7|0|20|14|2014|05| 5| 5|1419743229|28'],
            '2014-12-29 Monday of ISO week 1'     => [1419829629, '2015|15|01|52|52|363|1|1|20|14|2014|05| 5| 5|1419829629|29'],
            '2014-12-31'                          => [1420070399, '2015|15|01|52|52|365|3|3|20|14|2014|11|23|11|1420070399|31'],
            '2015-01-01'                          => [1420070400, '2015|15|01|00|00|001|4|4|20|15|2015|12| 0|12|1420070400| 1'],
            '2015-01-04 first Sunday'             => [1420372800, '2015|15|01|01|00|004|7|0|20|15|2015|12|12|12|1420372800| 4'],
            '2015-12-31 in a 53 week year'        => [1451563200, '2015|15|53|52|52|365|4|4|20|15|2015|12|12|12|1451563200|31'],
            '2016-01-01 in ISO week 53'           => [1451649600, '2015|15|53|00|00|001|5|5|20|16|2016|12|12|12|1451649600| 1'],
            '2016-01-03 Sunday of ISO week 53'    => [1451822400, '2015|15|53|01|00|003|7|0|20|16|2016|12|12|12|1451822400| 3'],
            '2016-12-31'                          => [1483185600, '2016|16|52|52|52|366|6|6|20|16|2016|12|12|12|1483185600|31'],
            '2017-01-01 Sunday in the old year'   => [1483272000, '2016|16|52|01|00|001|7|0|20|17|2017|12|12|12|1483272000| 1'],
            '2017-01-02'                          => [1483358400, '2017|17|01|01|01|002|1|1|20|17|2017|12|12|12|1483358400| 2'],
            '2018-12-31 Monday in the next year'  => [1546257600, '2019|19|01|52|53|365|1|1|20|18|2018|12|12|12|1546257600|31'],
            '2019-12-30'                          => [1577707200, '2020|20|01|52|52|364|1|1|20|19|2019|12|12|12|1577707200|30'],
            '2020-12-31 in a 53 week year'        => [1609416000, '2020|20|53|52|52|366|4|4|20|20|2020|12|12|12|1609416000|31'],
            '2021-01-01 in ISO week 53'           => [1609502400, '2020|20|53|00|00|001|5|5|20|21|2021|12|12|12|1609502400| 1'],
            '2021-01-03'                          => [1609675200, '2020|20|53|01|00|003|7|0|20|21|2021|12|12|12|1609675200| 3'],
            '2021-01-04'                          => [1609761600, '2021|21|01|01|01|004|1|1|20|21|2021|12|12|12|1609761600| 4'],
            '2026-01-01'                          => [1767268800, '2026|26|01|00|00|001|4|4|20|26|2026|12|12|12|1767268800| 1'],
            '2027-01-01 in ISO week 53'           => [1798804800, '2026|26|53|00|00|001|5|5|20|27|2027|12|12|12|1798804800| 1'],
            '2010-01-03'                          => [1262520000, '2009|09|53|01|00|003|7|0|20|10|2010|12|12|12|1262520000| 3'],
            '2009-12-31'                          => [1262260800, '2009|09|53|52|52|365|4|4|20|09|2009|12|12|12|1262260800|31'],
            '1998-12-31'                          => [915105600, '1998|98|53|52|52|365|4|4|19|98|1998|12|12|12|915105600|31'],
            '2004-12-31'                          => [1104494400, '2004|04|53|52|52|366|5|5|20|04|2004|12|12|12|1104494400|31'],
            '2024-12-30'                          => [1735560000, '2025|25|01|52|53|365|1|1|20|24|2024|12|12|12|1735560000|30'],
            '2032-12-31'                          => [1988107200, '2032|32|53|52|52|366|5|5|20|32|2032|12|12|12|1988107200|31'],
            '2000-01-01'                          => [946728000, '1999|99|52|00|00|001|6|6|20|00|2000|12|12|12|946728000| 1'],
            '2000-12-31'                          => [978264000, '2000|00|52|53|52|366|7|0|20|00|2000|12|12|12|978264000|31'],
            '1900-01-01'                          => [-2208945600, '1900|00|01|00|01|001|1|1|19|00|1900|12|12|12|-2208945600| 1'],
            '2100-01-01'                          => [4102488000, '2099|99|53|00|00|001|5|5|21|00|2100|12|12|12|4102488000| 1'],
            '2025-06-15 afternoon'                => [1749992829, '2025|25|24|24|23|166|7|0|20|25|2025|01|13| 1|1749992829|15'],
            'year 5'                              => [-62003904771, '5|05|09|09|09|064|6|6|0|05|5|05| 5| 5|-62003904771| 5'],
            'year 99'                             => [-59011462800, '99|99|53|52|52|365|4|4|0|99|99|11|23|11|-59011462800|31'],
            '1970-01-01 five seconds in'          => [5, '1970|70|01|00|00|001|4|4|19|70|1970|12| 0|12|5| 1'],
        ];

        foreach ($rows as $label => [$epoch, $expected]) {
            yield $label => [$epoch, $expected];
        }
    }

    public function testNoonIsPostMeridiem(): void
    {
        $noon = new BrokenDownTime(2020, 0, 1, 12, 0, 0, 3, 0);

        self::assertSame('pm PM', Strftime::format('%P %p', $noon));
        self::assertSame('am AM', Strftime::format('%P %p', new BrokenDownTime(2020, 0, 1, 11, 59, 59, 3, 0)));
    }

    public function testNegativeNumbersKeepTheirSignWithoutPadding(): void
    {
        $time = new BrokenDownTime(-5, 0, 1, 0, 0, 0, 3, 0);

        self::assertSame('-5', Strftime::format('%-Y', $time));
        self::assertSame('-5', Strftime::format('%Y', $time));
        self::assertSame('-005', Strftime::format('%04Y', $time));
    }

    public function testNumericOffsetsAreSplitIntoHoursAndMinutes(): void
    {
        self::assertSame('+0759', Strftime::format('%z', new BrokenDownTime(2020, 0, 1, 0, 0, 0, 3, 0, 28740, 'X')));
        self::assertSame('-0759', Strftime::format('%z', new BrokenDownTime(2020, 0, 1, 0, 0, 0, 3, 0, -28740, 'X')));
        self::assertSame('+1000', Strftime::format('%z', new BrokenDownTime(2020, 0, 1, 0, 0, 0, 3, 0, 36000, 'X')));
        self::assertSame('+0000', Strftime::format('%z', new BrokenDownTime(2020, 0, 1, 0, 0, 0, 3, 0, 59, 'X')));
        self::assertSame('+0001', Strftime::format('%z', new BrokenDownTime(2020, 0, 1, 0, 0, 0, 3, 0, 60, 'X')));
    }

    public function testWidthsOfDigitsAccumulate(): void
    {
        $time = new BrokenDownTime(2020, 0, 7, 0, 0, 0, 3, 0);

        self::assertSame('0000000007', Strftime::format('%010d', $time));
        self::assertSame('   7', Strftime::format('%_4e', $time));
        self::assertSame('07', Strftime::format('%2d', $time));
        self::assertSame('007', Strftime::format('%03d', $time));
    }
}
