<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Date;

/**
 * Proleptic Gregorian calendar arithmetic on integers (no DateTime objects), the way C's `timegm` and
 * `gmtime` work: out-of-range fields are normalised, negative values floor. MONTH_START is the 0-based
 * day-of-year of the first day of each month, for a common and a leap year.
 *
 * @internal
 */
final readonly class Civil
{
    public const int SECONDS_PER_DAY = 86400;

    private const array MONTH_START = [
        [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334, 365],
        [0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335, 366],
    ];

    private function __construct()
    {
    }

    public static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);

        return 0 !== $dividend % $divisor && (($dividend < 0) !== ($divisor < 0)) ? $quotient - 1 : $quotient;
    }

    public static function floorMod(int $dividend, int $divisor): int
    {
        return $dividend - self::floorDiv($dividend, $divisor) * $divisor;
    }

    public static function isLeapYear(int $year): bool
    {
        return (0 === $year % 4 && 0 !== $year % 100) || 0 === $year % 400;
    }

    /**
     * Days since 1970-01-01 of a calendar date; $month is 1 to 12 and must be in range.
     */
    public static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era   = self::floorDiv($year, 400);
        $yoe   = $year                                                                   - $era * 400;
        $doy   = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day            - 1;
        $doe   = $yoe * 365                                            + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * The calendar date of a day count since 1970-01-01.
     *
     * @return array{int, int, int} year, month (1 to 12), day of month
     */
    public static function civilFromDays(int $days): array
    {
        $days += 719468;
        $era   = self::floorDiv($days, 146097);
        $doe   = $days - $era * 146097;
        $yoe   = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy   = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp    = intdiv(5 * $doy + 2, 153);
        $day   = $doy                                                                                                                                                     - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp                                                                                                                                                                      + 3 : $mp - 9;

        return [$yoe + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    /**
     * Seconds since the epoch of a UTC broken-down time, like `timegm`: $month0 is 0 to 11 but any value
     * is carried into the year, and the other fields may overflow too.
     */
    public static function timegm(int $year, int $month0, int $day, int $hour, int $minute, int $second): int
    {
        $year  += self::floorDiv($month0, 12);
        $month0 = self::floorMod($month0, 12);
        $days   = self::daysFromCivil($year, $month0 + 1, 1) + $day - 1;

        return $days * self::SECONDS_PER_DAY + $hour * 3600 + $minute * 60 + $second;
    }

    /**
     * Day of the year, 0 based.
     */
    public static function dayOfYear(int $year, int $month0, int $day): int
    {
        return self::MONTH_START[self::isLeapYear($year) ? 1 : 0][$month0] + $day - 1;
    }

    /**
     * Day of the week of a date, 0 for Sunday.
     */
    public static function dayOfWeek(int $year, int $month0, int $day): int
    {
        return self::floorMod(self::daysFromCivil($year, $month0 + 1, 1) + $day - 1 + 4, 7);
    }

    /**
     * The 0 based month and day of month of a 0 based day of the year.
     *
     * @return array{int, int}
     */
    public static function monthAndDay(int $year, int $dayOfYear): array
    {
        $starts = self::MONTH_START[self::isLeapYear($year) ? 1 : 0];
        $month  = 0;
        while ($month < 11 && $starts[$month + 1] <= $dayOfYear) {
            ++$month;
        }

        return [$month, $dayOfYear - $starts[$month] + 1];
    }
}
