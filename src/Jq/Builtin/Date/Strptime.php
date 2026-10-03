<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Date;

/**
 * `strptime` as glibc implements it in the C locale, which is what jq calls: whitespace in the format
 * matches any whitespace, numeric conversions skip leading whitespace and are range checked, month and day
 * names match case-insensitively in full or abbreviated form, `%Z` swallows a run of non-blank characters,
 * `%z` takes Z, +hh, +hhmm or +hh:mm, and `%s` reads epoch seconds in the local zone.
 *
 * jq's own fix-ups apply: weekday and day of year are computed from the date when the format did not
 * supply them, and stay at the sentinels 8 and 367 when there is no day of month.
 *
 * @internal
 */
final class Strptime
{
    private const array DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    private const array MONTHS = [
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
    ];

    private const string SPACE = " \t\n\v\f\r";

    private int $position = 0;

    private int $year = 0;

    private int $month = 0;

    private int $day = 0;

    private int $hour = 0;

    private int $minute = 0;

    private int $second = 0;

    private int $weekday = 8;

    private int $yearDay = 367;

    private bool $haveWeekday = false;

    private bool $haveYearDay = false;

    private bool $haveMonth = false;

    private bool $haveDay = false;

    private bool $haveHour12 = false;

    private bool $pm = false;

    private bool $wantCentury = false;

    private bool $wantExtraDays = false;

    private int $century = -1;

    private int $weekNumber = -1;

    private bool $haveSundayWeek = false;

    private bool $haveMondayWeek = false;

    private function __construct(
        private readonly string $input,
        private readonly ZoneInfo $zone,
    ) {
    }

    /**
     * @return ?array{list<int>, string} the eight broken-down fields and the unparsed rest of the input,
     *                                   or null when the input does not match the format
     */
    public static function parse(string $input, string $format, ZoneInfo $localZone): ?array
    {
        $parser = new self($input, $localZone);
        if (!$parser->run($format)) {
            return null;
        }

        $parser->finish();
        $rest = substr($input, $parser->position);
        if ('' !== $rest && !str_contains(self::SPACE, $rest[0])) {
            return null;
        }

        return [
            [$parser->year + 1900, $parser->month, $parser->day, $parser->hour, $parser->minute, $parser->second, $parser->weekday, $parser->yearDay],
            $rest,
        ];
    }

    private function run(string $format): bool
    {
        $length = \strlen($format);
        for ($index = 0; $index < $length; ++$index) {
            $char = $format[$index];
            if (str_contains(self::SPACE, $char)) {
                $this->skipSpace();

                continue;
            }

            if ('%' !== $char) {
                if (($this->input[$this->position] ?? '') !== $char) {
                    return false;
                }

                ++$this->position;

                continue;
            }

            ++$index;
            while ($index < $length && str_contains('_-0^#', $format[$index])) {
                ++$index;
            }

            while ($index < $length && ctype_digit($format[$index])) {
                ++$index;
            }

            if ($index < $length && ('E' === $format[$index] || 'O' === $format[$index])) {
                ++$index;
            }

            if ($index >= $length || !$this->convert($format[$index])) {
                return false;
            }
        }

        return true;
    }

    private function convert(string $conversion): bool
    {
        switch ($conversion) {
            case '%':
                if (($this->input[$this->position] ?? '') !== '%') {
                    return false;
                }

                ++$this->position;

                return true;

            case 'a':
            case 'A':
                return $this->matchName(self::DAYS, static function (self $self, int $index): void {
                    $self->weekday     = $index;
                    $self->haveWeekday = true;
                });

            case 'b':
            case 'B':
            case 'h':
                $matched = $this->matchName(self::MONTHS, static function (self $self, int $index): void {
                    $self->month         = $index;
                    $self->haveMonth     = true;
                    $self->wantExtraDays = true;
                });

                return $matched;

            case 'c':
                $this->wantExtraDays = true;

                return $this->run('%a %b %e %H:%M:%S %Y');

            case 'C':
                return $this->number(0, 99, 2, function (int $value): void {
                    $this->century       = $value;
                    $this->wantExtraDays = true;
                });

            case 'd':
            case 'e':
                return $this->number(1, 31, 2, function (int $value): void {
                    $this->day           = $value;
                    $this->haveDay       = true;
                    $this->wantExtraDays = true;
                });

            case 'F':
                $this->wantExtraDays = true;

                return $this->run('%Y-%m-%d');

            case 'x':
            case 'D':
                $this->wantExtraDays = true;

                return $this->run('%m/%d/%y');

            case 'X':
            case 'T':
                return $this->run('%H:%M:%S');

            case 'R':
                return $this->run('%H:%M');

            case 'r':
                return $this->run('%I:%M:%S %p');

            case 'k':
            case 'H':
                return $this->number(0, 23, 2, function (int $value): void {
                    $this->hour       = $value;
                    $this->haveHour12 = false;
                });

            case 'l':
            case 'I':
                return $this->number(1, 12, 2, function (int $value): void {
                    $this->hour       = $value % 12;
                    $this->haveHour12 = true;
                });

            case 'j':
                return $this->number(1, 366, 3, function (int $value): void {
                    $this->yearDay     = $value - 1;
                    $this->haveYearDay = true;
                });

            case 'm':
                return $this->number(1, 12, 2, function (int $value): void {
                    $this->month         = $value - 1;
                    $this->haveMonth     = true;
                    $this->wantExtraDays = true;
                });

            case 'M':
                return $this->number(0, 59, 2, function (int $value): void {
                    $this->minute = $value;
                });

            case 'S':
                return $this->number(0, 61, 2, function (int $value): void {
                    $this->second = $value;
                });

            case 'n':
            case 't':
                $this->skipSpace();

                return true;

            case 'p':
            case 'P':
                return $this->meridian();

            case 's':
                return $this->epochSeconds();

            case 'u':
                return $this->number(1, 7, 1, function (int $value): void {
                    $this->weekday     = $value % 7;
                    $this->haveWeekday = true;
                });

            case 'w':
                return $this->number(0, 6, 1, function (int $value): void {
                    $this->weekday     = $value;
                    $this->haveWeekday = true;
                });

            case 'g':
            case 'V':
                return $this->number(0, 99, 2, static function (int $value): void {
                });

            case 'G':
                return $this->number(0, 9999, 4, static function (int $value): void {
                });

            case 'U':
                return $this->number(0, 53, 2, function (int $value): void {
                    $this->weekNumber     = $value;
                    $this->haveSundayWeek = true;
                });

            case 'W':
                return $this->number(0, 53, 2, function (int $value): void {
                    $this->weekNumber     = $value;
                    $this->haveMondayWeek = true;
                });

            case 'y':
                return $this->number(0, 99, 2, function (int $value): void {
                    $this->year          = $value >= 69 ? $value : $value + 100;
                    $this->wantCentury   = true;
                    $this->wantExtraDays = true;
                });

            case 'Y':
                return $this->number(0, 9999, 4, function (int $value): void {
                    $this->year          = $value - 1900;
                    $this->wantCentury   = false;
                    $this->wantExtraDays = true;
                });

            case 'Z':
                $this->skipSpace();
                while ($this->position < \strlen($this->input) && !str_contains(self::SPACE, $this->input[$this->position])) {
                    ++$this->position;
                }

                return true;

            case 'z':
                return $this->offset();

            default:
                return false;
        }
    }

    private function skipSpace(): void
    {
        $length = \strlen($this->input);
        while ($this->position < $length && str_contains(self::SPACE, $this->input[$this->position])) {
            ++$this->position;
        }
    }

    /**
     * glibc's get_number: skip blanks, read up to $digits digits while the value can still stay within $to.
     *
     * @param callable(int): void $store
     */
    private function number(int $from, int $to, int $digits, callable $store): bool
    {
        $this->skipSpace();
        $length = \strlen($this->input);
        if ($this->position >= $length || !ctype_digit($this->input[$this->position])) {
            return false;
        }

        $value = 0;
        do {
            $value = $value * 10 + (int)$this->input[$this->position];
            ++$this->position;
            --$digits;
        } while ($digits > 0 && $value * 10 <= $to && $this->position < $length && ctype_digit($this->input[$this->position]));

        if ($value < $from || $value > $to) {
            return false;
        }

        $store($value);

        return true;
    }

    /**
     * @param list<string>              $names lower case full names
     * @param callable(self, int): void $store
     */
    private function matchName(array $names, callable $store): bool
    {
        $rest = strtolower(substr($this->input, $this->position, 16));
        foreach ($names as $index => $name) {
            foreach ([$name, substr($name, 0, 3)] as $candidate) {
                if (str_starts_with($rest, $candidate)) {
                    $this->position += \strlen($candidate);
                    $store($this, $index);

                    return true;
                }
            }
        }

        return false;
    }

    private function meridian(): bool
    {
        $word = strtolower(substr($this->input, $this->position, 2));
        if ('am' !== $word && 'pm' !== $word) {
            return false;
        }

        $this->pm        = 'pm' === $word;
        $this->position += 2;

        return true;
    }

    private function epochSeconds(): bool
    {
        $length = \strlen($this->input);
        if ($this->position >= $length || !ctype_digit($this->input[$this->position])) {
            return false;
        }

        $start = $this->position;
        while ($this->position < $length && ctype_digit($this->input[$this->position])) {
            ++$this->position;
        }

        $time = BrokenDownTime::fromEpoch((int)substr($this->input, $start, $this->position - $start), $this->zone);
        if (null === $time) {
            return false;
        }

        $this->year          = $time->year - 1900;
        $this->month         = $time->month;
        $this->day           = $time->day;
        $this->hour          = $time->hour;
        $this->minute        = $time->minute;
        $this->second        = $time->second;
        $this->weekday       = $time->weekday;
        $this->yearDay       = $time->yearDay;
        $this->haveWeekday   = true;
        $this->haveYearDay   = true;
        $this->haveMonth     = true;
        $this->haveDay       = true;
        $this->wantExtraDays = false;

        return true;
    }

    /**
     * `%z`: Z, or a sign with two digits, four digits, or two digits, a colon and two digits. The offset is
     * validated and consumed; the broken-down time jq returns has no field for it.
     */
    private function offset(): bool
    {
        $this->skipSpace();
        $char = $this->input[$this->position] ?? '';
        if ('Z' === $char) {
            ++$this->position;

            return true;
        }

        if ('+' !== $char && '-' !== $char) {
            return false;
        }

        ++$this->position;
        $value  = 0;
        $digits = 0;
        $length = \strlen($this->input);
        while ($digits < 4 && $this->position < $length && ctype_digit($this->input[$this->position])) {
            $value = $value * 10 + (int)$this->input[$this->position];
            ++$this->position;
            ++$digits;
            if (2 === $digits && ':' === ($this->input[$this->position] ?? '') && ctype_digit($this->input[$this->position + 1] ?? '')) {
                ++$this->position;
            }
        }

        return 2 === $digits || (4 === $digits && $value % 100 < 60);
    }

    /**
     * glibc's end-of-format adjustments, then jq's weekday and day-of-year fallbacks.
     */
    private function finish(): void
    {
        if ($this->haveHour12 && $this->pm) {
            $this->hour += 12;
        }

        if ($this->century >= 0) {
            $this->year = $this->wantCentury ? $this->year % 100 + ($this->century - 19) * 100 : ($this->century - 19) * 100;
        }

        $fullYear = $this->year + 1900;
        if ($this->wantExtraDays && !$this->haveWeekday) {
            if (!($this->haveMonth && $this->haveDay) && $this->haveYearDay) {
                [$month, $day] = Civil::monthAndDay($fullYear, $this->yearDay);
                if (!$this->haveMonth) {
                    $this->month = $month;
                }

                if (!$this->haveDay) {
                    $this->day = $day;
                }

                $this->haveMonth = true;
                $this->haveDay   = true;
            }

            if ($this->month >= 0 && $this->month <= 11) {
                $this->weekday = Civil::dayOfWeek($fullYear, $this->month, $this->day);
            }
        }

        if ($this->wantExtraDays && !$this->haveYearDay && $this->month >= 0 && $this->month <= 11) {
            $this->yearDay = Civil::dayOfYear($fullYear, $this->month, $this->day);
        }

        if (($this->haveSundayWeek || $this->haveMondayWeek) && $this->haveWeekday) {
            $this->applyWeekNumber($fullYear);
        }

        if (8 === $this->weekday && $this->day >= 1 && $this->day <= 31) {
            $this->weekday = Civil::dayOfWeek($fullYear, max(0, min(11, $this->month)), $this->day);
        }

        if (367 === $this->yearDay && $this->day >= 1 && $this->day <= 31) {
            $this->yearDay = Civil::dayOfYear($fullYear, max(0, min(11, $this->month)), $this->day);
        }
    }

    private function applyWeekNumber(int $fullYear): void
    {
        $weekday     = $this->weekday;
        $weekOffset  = $this->haveSundayWeek ? 0 : 1;
        $firstWeekday = Civil::dayOfWeek($fullYear, 0, 1);
        if (!$this->haveYearDay) {
            $this->yearDay = Civil::floorMod(7 - ($firstWeekday - $weekOffset), 7)
                + ($this->weekNumber - 1) * 7
                + Civil::floorMod($weekday - $weekOffset + 7, 7);
        }

        if (!$this->haveDay || !$this->haveMonth) {
            [$month, $day] = Civil::monthAndDay($fullYear, $this->yearDay);
            if (!$this->haveMonth) {
                $this->month = $month;
            }

            if (!$this->haveDay) {
                $this->day = $day;
            }
        }

        $this->weekday = $weekday;
    }
}
