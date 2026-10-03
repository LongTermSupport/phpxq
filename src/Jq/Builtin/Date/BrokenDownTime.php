<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Date;

use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;

/**
 * C's `struct tm` as jq sees it: a calendar date and wall-clock time plus weekday and day of the year,
 * and the zone data `strftime` prints for `%z` and `%Z`.
 *
 * @internal
 */
final readonly class BrokenDownTime
{
    /**
     * The largest |seconds since the epoch| whose year still fits the 32-bit `tm_year` of glibc.
     */
    private const int EPOCH_LIMIT = 67768036191676799;

    private const int INT_MAX = 2147483647;

    private const int INT_MIN = -2147483648;

    /**
     * @param int $year      the full year (`tm_year + 1900`)
     * @param int $month     0 to 11
     * @param int $day       day of the month, 1 to 31
     * @param int $weekday   0 for Sunday
     * @param int $yearDay   0 based
     * @param int $gmtOffset seconds east of UTC
     */
    public function __construct(
        public int $year,
        public int $month,
        public int $day,
        public int $hour,
        public int $minute,
        public int $second,
        public int $weekday,
        public int $yearDay,
        public int $gmtOffset = 0,
        public string $zone = 'UTC',
        public bool $dst = false,
    ) {
    }

    /**
     * The wall-clock time of an instant in a zone, or null when the year would not fit a C `int`.
     */
    public static function fromEpoch(int $seconds, ZoneInfo $zone): ?self
    {
        if (abs($seconds) > self::EPOCH_LIMIT) {
            return null;
        }

        [$offset, $dst, $abbreviation] = $zone->at($seconds);
        $local                         = $seconds + $offset;
        $days                          = Civil::floorDiv($local, Civil::SECONDS_PER_DAY);
        $rest                          = $local - $days * Civil::SECONDS_PER_DAY;
        [$year, $month, $day]          = Civil::civilFromDays($days);

        return new self(
            $year,
            $month - 1,
            $day,
            intdiv($rest, 3600),
            intdiv($rest % 3600, 60),
            $rest % 60,
            Civil::floorMod($days + 4, 7),
            $days - Civil::daysFromCivil($year, 1, 1),
            $offset,
            $abbreviation,
            $dst,
        );
    }

    /**
     * Reads jq's broken-down time array like `jv2tm`: elements in the order year, month, day, hour, minute,
     * second, weekday, day of year; missing ones are 0; fractions truncate toward zero and values clamp to a
     * C `int`. Returns null when an element is not a number.
     *
     * @param array<array-key, mixed> $values
     */
    public static function fromJq(array $values): ?self
    {
        $fields = [];
        for ($index = 0; $index < 8; ++$index) {
            if (!\array_key_exists($index, $values)) {
                $fields[] = 0;

                continue;
            }

            $number = $values[$index];
            if (!\is_int($number) && !\is_float($number) && !$number instanceof PreciseNumber) {
                return null;
            }

            $fields[] = self::clamp(Values::toFloat($number) - (0 === $index ? 1900.0 : 0.0));
        }

        return new self($fields[0] + 1900, $fields[1], $fields[2], $fields[3], $fields[4], $fields[5], $fields[6], $fields[7]);
    }

    /**
     * jq's eight element array: year, month, day, hour, minute, second, weekday, day of year.
     *
     * @return list<int>
     */
    public function toList(): array
    {
        return [$this->year, $this->month, $this->day, $this->hour, $this->minute, $this->second, $this->weekday, $this->yearDay];
    }

    /**
     * Seconds since the epoch if the fields were UTC (`timegm`), with every field normalised.
     */
    public function wallSeconds(): int
    {
        return Civil::timegm($this->year, $this->month, $this->day, $this->hour, $this->minute, $this->second);
    }

    private static function clamp(float $value): int
    {
        if (is_nan($value)) {
            return 0;
        }

        if ($value >= self::INT_MAX) {
            return self::INT_MAX;
        }

        if ($value <= self::INT_MIN) {
            return self::INT_MIN;
        }

        return (int)$value;
    }
}
