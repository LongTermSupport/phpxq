<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Date;

/**
 * `strftime` with the glibc conversion set in the C locale: %a %A %b %B %c %C %d %D %e %F %g %G %h %H %I %j
 * %k %l %m %M %n %p %P %r %R %s %S %t %T %u %U %V %w %W %x %X %y %Y %z (and %:z) %Z %%, the flags `_ - 0 ^ #`,
 * a field width, and the `E`/`O` modifiers (accepted, no effect). An unknown conversion is copied through.
 *
 * @internal
 */
final readonly class Strftime
{
    private const array DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private const array MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    private function __construct()
    {
    }

    public static function format(string $format, BrokenDownTime $time): string
    {
        $length = \strlen($format);
        $out    = '';
        $index  = 0;

        while ($index < $length) {
            $percent = strpos($format, '%', $index);
            if (false === $percent) {
                return $out . substr($format, $index);
            }

            $out  .= substr($format, $index, $percent - $index);
            $index = $percent + 1;
            $flag  = null;
            while ($index < $length && str_contains('_-0^#', $format[$index])) {
                $flag = $format[$index];
                ++$index;
            }

            $width = null;
            while ($index < $length && ctype_digit($format[$index])) {
                $width = ($width ?? 0) * 10 + (int)$format[$index];
                ++$index;
            }

            if ($index < $length && ('E' === $format[$index] || 'O' === $format[$index])) {
                ++$index;
            }

            if ($index >= $length) {
                return $out . substr($format, $percent);
            }

            $conversion = $format[$index];
            ++$index;
            $converted = self::convert($conversion, $flag, $width, $time);
            $out      .= $converted ?? substr($format, $percent, $index - $percent);
        }

        return $out;
    }

    private static function convert(string $conversion, ?string $flag, ?int $width, BrokenDownTime $time): ?string
    {
        $hour12 = 0 === $time->hour % 12 ? 12 : $time->hour % 12;

        return match ($conversion) {
            'a'      => self::text(substr(self::DAYS[self::index($time->weekday, 7)], 0, 3), $conversion, $flag, $width),
            'A'      => self::text(self::DAYS[self::index($time->weekday, 7)], $conversion, $flag, $width),
            'b', 'h' => self::text(substr(self::MONTHS[self::index($time->month, 12)], 0, 3), $conversion, $flag, $width),
            'B'      => self::text(self::MONTHS[self::index($time->month, 12)], $conversion, $flag, $width),
            'c'      => self::text(self::format('%a %b %e %H:%M:%S %Y', $time), $conversion, $flag, $width),
            'C'      => self::number(Civil::floorDiv($time->year, 100), 1, '0', $flag, $width),
            'd'      => self::number($time->day, 2, '0', $flag, $width),
            'D'      => self::text(self::format('%m/%d/%y', $time), $conversion, $flag, $width),
            'e'      => self::number($time->day, 2, ' ', $flag, $width),
            'F'      => self::text(self::format('%Y-%m-%d', $time), $conversion, $flag, $width),
            'g'      => self::number(Civil::floorMod(self::isoWeek($time)[0], 100), 2, '0', $flag, $width),
            'G'      => self::number(self::isoWeek($time)[0], 1, '0', $flag, $width),
            'H'      => self::number($time->hour, 2, '0', $flag, $width),
            'I'      => self::number($hour12, 2, '0', $flag, $width),
            'j'      => self::number($time->yearDay + 1, 3, '0', $flag, $width),
            'k'      => self::number($time->hour, 2, ' ', $flag, $width),
            'l'      => self::number($hour12, 2, ' ', $flag, $width),
            'm'      => self::number($time->month + 1, 2, '0', $flag, $width),
            'M'      => self::number($time->minute, 2, '0', $flag, $width),
            'n'      => self::text("\n", $conversion, $flag, $width),
            'p'      => self::text($time->hour >= 12 ? 'PM' : 'AM', $conversion, $flag, $width),
            'P'      => self::text($time->hour >= 12 ? 'pm' : 'am', $conversion, $flag, $width),
            'r'      => self::text(self::format('%I:%M:%S %p', $time), $conversion, $flag, $width),
            'R'      => self::text(self::format('%H:%M', $time), $conversion, $flag, $width),
            's'      => self::number($time->wallSeconds() - $time->gmtOffset, 1, '0', $flag, $width),
            'S'      => self::number($time->second, 2, '0', $flag, $width),
            't'      => self::text("\t", $conversion, $flag, $width),
            'T'      => self::text(self::format('%H:%M:%S', $time), $conversion, $flag, $width),
            'u'      => self::number(0 === $time->weekday ? 7 : $time->weekday, 1, '0', $flag, $width),
            'U'      => self::number(intdiv($time->yearDay + 7 - $time->weekday, 7), 2, '0', $flag, $width),
            'V'      => self::number(self::isoWeek($time)[1], 2, '0', $flag, $width),
            'w'      => self::number($time->weekday, 1, '0', $flag, $width),
            'W'      => self::number(intdiv($time->yearDay + 7 - ($time->weekday + 6) % 7, 7), 2, '0', $flag, $width),
            'x'      => self::text(self::format('%m/%d/%y', $time), $conversion, $flag, $width),
            'X'      => self::text(self::format('%H:%M:%S', $time), $conversion, $flag, $width),
            'y'      => self::number(Civil::floorMod($time->year, 100), 2, '0', $flag, $width),
            'Y'      => self::number($time->year, 1, '0', $flag, $width),
            'z'      => self::text(self::offset($time->gmtOffset), $conversion, $flag, $width),
            'Z'      => self::text($time->zone, $conversion, $flag, $width),
            '%'      => '%',
            default  => null,
        };
    }

    /**
     * @param int<2, 12>|int $modulus
     */
    private static function index(int $value, int $modulus): int
    {
        return Civil::floorMod($value, $modulus);
    }

    private static function number(int $value, int $defaultWidth, string $defaultPad, ?string $flag, ?int $width): string
    {
        $digits = (string)abs($value);
        $sign   = $value < 0 ? '-' : '';
        if ('-' === $flag) {
            return $sign . $digits;
        }

        $pad   = match ($flag) {
            '_'     => ' ',
            '0'     => '0',
            default => $defaultPad,
        };
        $width = ($width ?? $defaultWidth) - \strlen($sign);

        if ('0' === $pad) {
            return $sign . str_pad($digits, max($width, 0), '0', \STR_PAD_LEFT);
        }

        return str_pad($sign . $digits, max($width + \strlen($sign), 0), ' ', \STR_PAD_LEFT);
    }

    private static function text(string $text, string $conversion, ?string $flag, ?int $width): string
    {
        if ('^' === $flag) {
            $text = strtoupper($text);
        } elseif ('#' === $flag) {
            $text = str_contains('pZ', $conversion) ? strtolower($text) : strtoupper($text);
        }

        if (null === $width) {
            return $text;
        }

        return str_pad($text, $width, '0' === $flag ? '0' : ' ', \STR_PAD_LEFT);
    }

    /**
     * `%z`: sign, hours and minutes, as glibc prints it (seconds of the offset are dropped).
     */
    private static function offset(int $seconds): string
    {
        $minutes = intdiv(abs($seconds), 60);

        return \sprintf('%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * ISO 8601 week-based year and week number.
     *
     * @return array{int, int}
     */
    private static function isoWeek(BrokenDownTime $time): array
    {
        $weekday = 0 === $time->weekday ? 7 : $time->weekday;
        $week    = intdiv($time->yearDay + 1 - $weekday + 10, 7);
        $year    = $time->year;

        if ($week < 1) {
            --$year;

            return [$year, self::weeksInYear($year)];
        }

        if ($week > self::weeksInYear($year)) {
            return [$year + 1, 1];
        }

        return [$year, $week];
    }

    private static function weeksInYear(int $year): int
    {
        $p = static fn (int $y): int => Civil::floorMod($y + Civil::floorDiv($y, 4) - Civil::floorDiv($y, 100) + Civil::floorDiv($y, 400), 7);

        return 52 + (4 === $p($year) || 3 === $p($year - 1) ? 1 : 0);
    }
}
