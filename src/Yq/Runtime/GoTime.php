<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Go's `time` layouts and durations on top of DateTimeImmutable: formatting and parsing with
 * reference-time layouts such as `Monday, 02-Jan-06 at 3:04PM MST`, and `ParseDuration` strings. Layout
 * tokens made of digits get symbolic names (SYMBOLS): `switch` compares numeric strings numerically, so `3`
 * and `03` would otherwise be the same case.
 */
final class GoTime
{
    public const string RFC3339 = '2006-01-02T15:04:05Z07:00';

    private const array FALLBACK_LAYOUTS = [
        self::RFC3339,
        '2006-01-02T15:04:05',
        '2006-01-02 15:04:05Z07:00',
        '2006-01-02 15:04:05',
        '2006-01-02',
    ];

    private const array UNITS_NS = [
        'ns' => 1,
        'us' => 1000,
        'µs' => 1000,
        'μs' => 1000,
        'ms' => 1000000,
        's'  => 1000000000,
        'm'  => 60000000000,
        'h'  => 3600000000000,
    ];

    private const string KIND_LITERAL = 'lit';

    private const string KIND_STANDARD = 'std';

    private const string UTC = 'UTC';

    private const string YEAR_FOUR_DIGITS = '2006';

    private const string SHORT_MONTH = 'Jan';

    private const string LONG_MONTH = 'January';

    private const string SHORT_WEEKDAY = 'Mon';

    private const string LONG_WEEKDAY = 'Monday';

    private const string ZONE_NAME = 'MST';

    private const string OFFSET_HOURS_MINUTES = '-0700';

    private const string OFFSET_HOURS_COLON_MINUTES = '-07:00';

    private const string OFFSET_HOURS = '-07';

    private const string OFFSET_HOURS_MINUTES_SECONDS = '-070000';

    private const string OFFSET_HOURS_COLON_MINUTES_COLON_SECONDS = '-07:00:00';

    private const string ZULU_HOURS_MINUTES = 'Z0700';

    private const string ZULU_HOURS_COLON_MINUTES = 'Z07:00';

    private const string ZULU_HOURS = 'Z07';

    private const string ZULU_HOURS_MINUTES_SECONDS = 'Z070000';

    private const string ZULU_HOURS_COLON_MINUTES_COLON_SECONDS = 'Z07:00:00';

    private const array STD_LONG_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const array SYMBOLS = [
        '2006' => 'Y4', '06' => 'Y2', '01' => 'M2', '1' => 'M1', '02' => 'D2', '2' => 'D1', '002' => 'YD',
        '15'   => 'H24', '03' => 'H2', '3' => 'H1', '04' => 'I2', '4' => 'I1', '05' => 'S2', '5' => 'S1',
    ];

    /** @var array<string, list<array{string, string}>> */
    private static array $layouts = [];

    private function __construct()
    {
    }

    public static function format(DateTimeImmutable $time, string $layout): string
    {
        $out = '';
        foreach (self::tokens($layout) as [$kind, $text]) {
            $out .= self::KIND_LITERAL === $kind ? $text : self::formatStd($time, $text);
        }

        return $out;
    }

    /**
     * Parses `$value` with a Go layout; null when it does not match.
     */
    public static function parse(string $layout, string $value): ?DateTimeImmutable
    {
        $p      = 0;
        $n      = \strlen($value);
        $y      = 0;
        $mo     = 1;
        $d      = 1;
        $h      = 0;
        $mi     = 0;
        $s      = 0;
        $ns     = 0;
        $pm     = null;
        $tz     = null;
        $tokens = self::tokens($layout);
        $count  = \count($tokens);
        foreach ($tokens as $index => [$kind, $text]) {
            if (self::KIND_LITERAL === $kind) {
                if (substr($value, $p, \strlen($text)) !== $text) {
                    return null;
                }

                $p += \strlen($text);

                continue;
            }

            switch ($text) {
                case 'Y4':
                    $y = self::digits($value, $p, 4, 4);
                    break;
                case 'Y2':
                    $y = self::digits($value, $p, 2, 2);
                    if (null !== $y) {
                        $y += $y >= 69 ? 1900 : 2000;
                    }

                    break;
                case 'M2':
                    $mo = self::digits($value, $p, 2, 2);
                    break;
                case 'M1':
                    $mo = self::digits($value, $p, 1, 2);
                    break;
                case self::SHORT_MONTH:
                case self::LONG_MONTH:
                    $mo = self::monthName($value, $p, self::SHORT_MONTH === $text);
                    break;
                case 'D2':
                    $d = self::digits($value, $p, 2, 2);
                    break;
                case 'D1':
                    $d = self::digits($value, $p, 1, 2);
                    break;
                case '_2':
                    if (' ' === substr($value, $p, 1)) {
                        ++$p;
                    }

                    $d = self::digits($value, $p, 1, 2);
                    break;
                case 'YD':
                    $yday = self::digits($value, $p, 3, 3);
                    if (null === $yday) {
                        return null;
                    }

                    $mo = 1;
                    $d  = $yday;
                    break;
                case 'H24':
                case 'H2':
                    $h = self::digits($value, $p, 2, 2);
                    break;
                case 'H1':
                    $h = self::digits($value, $p, 1, 2);
                    break;
                case 'I2':
                    $mi = self::digits($value, $p, 2, 2);
                    break;
                case 'I1':
                    $mi = self::digits($value, $p, 1, 2);
                    break;
                case 'S2':
                case 'S1':
                    $s = self::digits($value, $p, 'S1' === $text ? 1 : 2, 2);
                    if (null === $s) {
                        return null;
                    }

                    $nextIsFraction = isset($tokens[$index + 1]) && self::KIND_STANDARD === $tokens[$index + 1][0] && \in_array($tokens[$index + 1][1][0], ['.', ','], true);
                    if (!$nextIsFraction && '.' === substr($value, $p, 1) && $p + 1 < $n && ctype_digit($value[$p + 1])) {
                        $ns = self::fraction($value, $p);
                    }

                    break;
                case 'PM':
                case 'pm':
                    $word = strtoupper(substr($value, $p, 2));
                    if ('PM' !== $word && 'AM' !== $word) {
                        return null;
                    }

                    $pm = 'PM' === $word;
                    $p += 2;
                    break;
                case self::SHORT_WEEKDAY:
                case self::LONG_WEEKDAY:
                    $len = 0;
                    while ($p + $len < $n && ctype_alpha($value[$p + $len])) {
                        ++$len;
                    }

                    if ($len < 3) {
                        return null;
                    }

                    $p += $len;
                    break;
                case self::ZONE_NAME:
                    $tz = self::parseZoneName($value, $p);
                    if (!$tz instanceof DateTimeZone) {
                        return null;
                    }

                    break;
                case self::OFFSET_HOURS_MINUTES:
                case self::OFFSET_HOURS_COLON_MINUTES:
                case self::OFFSET_HOURS:
                case self::OFFSET_HOURS_MINUTES_SECONDS:
                case self::OFFSET_HOURS_COLON_MINUTES_COLON_SECONDS:
                case self::ZULU_HOURS_MINUTES:
                case self::ZULU_HOURS_COLON_MINUTES:
                case self::ZULU_HOURS:
                case self::ZULU_HOURS_MINUTES_SECONDS:
                case self::ZULU_HOURS_COLON_MINUTES_COLON_SECONDS:
                    $tz = self::parseOffset($value, $p, $text);
                    if (!$tz instanceof DateTimeZone) {
                        return null;
                    }

                    break;
                default:
                    if ('.' !== $text[0] && ',' !== $text[0]) {
                        return null;
                    }

                    $current = substr($value, $p, 1);
                    if ($current !== $text[0] && ('.' !== $current || ',' !== $text[0])) {
                        return null;
                    }

                    $ns = self::fraction($value, $p);
                    break;
            }

            if (\in_array(null, [$y, $mo, $d, $h, $mi], true)) {
                return null;
            }
        }

        if ($p !== $n || $count < 1) {
            return null;
        }

        if (true === $pm && $h < 12) {
            $h += 12;
        } elseif (false === $pm && 12 === $h) {
            $h = 0;
        }

        if ($mo < 1 || $mo > 12 || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        $zone = $tz ?? new DateTimeZone(self::UTC);
        if ($d < 1 || $d > 31 || ($y > 0 && !checkdate($mo, $d, $y))) {
            return null;
        }

        return new DateTimeImmutable('now', $zone)->setDate($y, $mo, $d)->setTime($h, $mi, $s, intdiv($ns, 1000));
    }

    /**
     * True for text the YAML core schema reads as a timestamp (a date, optionally with a time).
     */
    public static function looksLikeTimestamp(string $text): bool
    {
        return 1 === preg_match('/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}(?:(?:[Tt]|[ \t]+)[0-9]{1,2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]*)?(?:[ \t]*(?:Z|[-+][0-9]{1,2}(?::[0-9]{2})?))?)?$/D', $text);
    }

    /**
     * Parses with the given layout, or with the common layouts when none is set; null when nothing fits.
     */
    public static function tryParse(string $value, ?string $layout = null): ?DateTimeImmutable
    {
        if (null !== $layout) {
            return self::parse($layout, $value);
        }

        if (1 !== preg_match('/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}/', $value)) {
            return null;
        }

        foreach (self::FALLBACK_LAYOUTS as $fallback) {
            $time = self::parse($fallback, $value);
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }

        return null;
    }

    /**
     * `time.ParseDuration`, as nanoseconds; null when the text is not a duration.
     */
    public static function parseDuration(string $text): ?int
    {
        if ('' === $text) {
            return null;
        }

        $sign = 1;
        $rest = $text;
        if ('-' === $rest[0] || '+' === $rest[0]) {
            $sign = '-' === $rest[0] ? -1 : 1;
            $rest = substr($rest, 1);
        }

        if ('0' === $rest) {
            return 0;
        }

        if ('' === $rest) {
            return null;
        }

        $total = 0.0;
        while ('' !== $rest) {
            if (1 !== preg_match('/^([0-9]*\.?[0-9]*)(ns|us|µs|μs|ms|s|m|h)/u', $rest, $m) || '' === $m[1] || '.' === $m[1]) {
                return null;
            }

            $total += (float)$m[1] * self::UNITS_NS[$m[2]];
            $rest = substr($rest, \strlen($m[0]));
        }

        return $sign * (int)round($total);
    }

    /**
     * Adds nanoseconds (negative to subtract) to a time.
     */
    public static function addNanos(DateTimeImmutable $time, int $nanos): DateTimeImmutable
    {
        $micro = (int)$time->format('u') + intdiv($nanos % 1000000000, 1000);
        $secs  = intdiv($nanos, 1000000000);
        if ($micro >= 1000000) {
            $micro -= 1000000;
            ++$secs;
        } elseif ($micro < 0) {
            $micro += 1000000;
            --$secs;
        }

        $moved = $time->setTimestamp($time->getTimestamp() + $secs);

        return $moved->setTime((int)$moved->format('H'), (int)$moved->format('i'), (int)$moved->format('s'), $micro);
    }

    /**
     * @return list<array{string, string}> tokens of kind `lit` or `std`
     */
    private static function tokens(string $layout): array
    {
        if (isset(self::$layouts[$layout])) {
            return self::$layouts[$layout];
        }

        $tokens = [];
        $lit    = '';
        $n      = \strlen($layout);
        $i      = 0;
        while ($i < $n) {
            $std = self::stdAt($layout, $i, $n);
            if (null === $std) {
                $lit .= $layout[$i];
                ++$i;

                continue;
            }

            if ('' !== $lit) {
                $tokens[] = [self::KIND_LITERAL, $lit];
                $lit      = '';
            }

            $tokens[] = [self::KIND_STANDARD, self::SYMBOLS[$std] ?? $std];
            $i += \strlen($std);
        }

        if ('' !== $lit) {
            $tokens[] = [self::KIND_LITERAL, $lit];
        }

        if (\count(self::$layouts) > 128) {
            self::$layouts = [];
        }

        self::$layouts[$layout] = $tokens;

        return $tokens;
    }

    private static function stdAt(string $layout, int $i, int $n): ?string
    {
        $c    = $layout[$i];
        $next = substr($layout, $i + 1, 1);
        switch ($c) {
            case 'J':
                if (self::SHORT_MONTH === substr($layout, $i, 3)) {
                    return self::LONG_MONTH === substr($layout, $i, 7) ? self::LONG_MONTH : self::SHORT_MONTH;
                }

                return null;

            case 'M':
                if (self::SHORT_WEEKDAY === substr($layout, $i, 3)) {
                    return self::LONG_WEEKDAY === substr($layout, $i, 6) ? self::LONG_WEEKDAY : self::SHORT_WEEKDAY;
                }

                return self::ZONE_NAME === substr($layout, $i, 3) ? self::ZONE_NAME : null;

            case '0':
                if ($next >= '1' && $next <= '6') {
                    return '0' . $next;
                }

                return '002' === substr($layout, $i, 3) ? '002' : null;

            case '1':
                return '5' === $next ? '15' : '1';

            case '2':
                return self::YEAR_FOUR_DIGITS === substr($layout, $i, 4) ? self::YEAR_FOUR_DIGITS : '2';

            case '_':
                if ('2' === $next) {
                    return self::YEAR_FOUR_DIGITS === substr($layout, $i + 1, 4) ? null : '_2';
                }

                return null;

            case '3':
            case '4':
            case '5':
                return $c;

            case 'P':
                return 'M' === $next ? 'PM' : null;

            case 'p':
                return 'm' === $next ? 'pm' : null;

            case '-':
                foreach ([self::OFFSET_HOURS_MINUTES_SECONDS, self::OFFSET_HOURS_COLON_MINUTES_COLON_SECONDS, self::OFFSET_HOURS_MINUTES, self::OFFSET_HOURS_COLON_MINUTES, self::OFFSET_HOURS] as $std) {
                    if (substr($layout, $i, \strlen($std)) === $std) {
                        return $std;
                    }
                }

                return null;

            case 'Z':
                foreach ([self::ZULU_HOURS_MINUTES_SECONDS, self::ZULU_HOURS_COLON_MINUTES_COLON_SECONDS, self::ZULU_HOURS_MINUTES, self::ZULU_HOURS_COLON_MINUTES, self::ZULU_HOURS] as $std) {
                    if (substr($layout, $i, \strlen($std)) === $std) {
                        return $std;
                    }
                }

                return null;

            case '.':
            case ',':
                if ('0' === $next || '9' === $next) {
                    $j = $i + 1;
                    while ($j < $n && $layout[$j] === $next) {
                        ++$j;
                    }

                    $after = substr($layout, $j, 1);
                    if ($after < '0' || $after > '9') {
                        return substr($layout, $i, $j - $i);
                    }
                }

                return null;

            default:
                return null;
        }
    }

    private static function formatStd(DateTimeImmutable $t, string $std): string
    {
        return match ($std) {
            'Y4'                                           => $t->format('Y'),
            'Y2'                                           => $t->format('y'),
            'M2'                                           => $t->format('m'),
            'M1'                                           => $t->format('n'),
            self::SHORT_MONTH                              => $t->format('M'),
            self::LONG_MONTH                               => $t->format('F'),
            'D2'                                           => $t->format('d'),
            'D1'                                           => $t->format('j'),
            '_2'                                           => str_pad($t->format('j'), 2, ' ', \STR_PAD_LEFT),
            'YD'                                           => str_pad((string)((int)$t->format('z') + 1), 3, '0', \STR_PAD_LEFT),
            'H24'                                          => $t->format('H'),
            'H2'                                           => $t->format('h'),
            'H1'                                           => $t->format('g'),
            'I2'                                           => $t->format('i'),
            'I1'                                           => (string)(int)$t->format('i'),
            'S2'                                           => $t->format('s'),
            'S1'                                           => (string)(int)$t->format('s'),
            'PM'                                           => $t->format('A'),
            'pm'                                           => $t->format('a'),
            self::SHORT_WEEKDAY                            => $t->format('D'),
            self::LONG_WEEKDAY                             => $t->format('l'),
            self::ZONE_NAME                                => self::zoneName($t),
            self::OFFSET_HOURS_MINUTES                     => $t->format('O'),
            self::OFFSET_HOURS_COLON_MINUTES               => $t->format('P'),
            self::OFFSET_HOURS                             => substr($t->format('O'), 0, 3),
            self::OFFSET_HOURS_MINUTES_SECONDS             => $t->format('O') . '00',
            self::OFFSET_HOURS_COLON_MINUTES_COLON_SECONDS => $t->format('P') . ':00',
            self::ZULU_HOURS_MINUTES                       => 0                             === $t->getOffset() ? 'Z' : $t->format('O'),
            self::ZULU_HOURS_COLON_MINUTES                 => 0                             === $t->getOffset() ? 'Z' : $t->format('P'),
            self::ZULU_HOURS                               => 0                             === $t->getOffset() ? 'Z' : substr($t->format('O'), 0, 3),
            self::ZULU_HOURS_MINUTES_SECONDS               => 0                             === $t->getOffset() ? 'Z' : $t->format('O') . '00',
            self::ZULU_HOURS_COLON_MINUTES_COLON_SECONDS   => 0                             === $t->getOffset() ? 'Z' : $t->format('P') . ':00',
            default                                        => self::formatFraction($t, $std),
        };
    }

    private static function formatFraction(DateTimeImmutable $t, string $std): string
    {
        $digits = \strlen($std) - 1;
        $micro  = str_pad($t->format('u'), 9, '0', \STR_PAD_RIGHT);
        if ('9' === $std[1]) {
            $fraction = rtrim(substr($micro, 0, $digits), '0');

            return '' === $fraction ? '' : $std[0] . $fraction;
        }

        return $std[0] . str_pad(substr($micro, 0, $digits), $digits, '0', \STR_PAD_RIGHT);
    }

    private static function zoneName(DateTimeImmutable $t): string
    {
        $name = $t->format('T');
        if ('+' === $name[0] || '-' === $name[0]) {
            return 0 === $t->getOffset() ? self::UTC : substr($t->format('O'), 0, 3);
        }

        return 'Z' === $name ? self::UTC : $name;
    }

    private static function digits(string $value, int &$p, int $min, int $max): ?int
    {
        $len = 0;
        $n   = \strlen($value);
        while ($len < $max && $p + $len < $n && $value[$p + $len] >= '0' && $value[$p + $len] <= '9') {
            ++$len;
        }

        if ($len < $min) {
            return null;
        }

        $number = (int)substr($value, $p, $len);
        $p += $len;

        return $number;
    }

    private static function monthName(string $value, int &$p, bool $short): ?int
    {
        foreach (self::STD_LONG_MONTHS as $index => $name) {
            $candidate = $short ? substr($name, 0, 3) : $name;
            if (0 === strncasecmp(substr($value, $p, \strlen($candidate)), $candidate, \strlen($candidate))) {
                $p += \strlen($candidate);

                return $index + 1;
            }
        }

        return null;
    }

    private static function fraction(string $value, int &$p): int
    {
        ++$p;
        $start = $p;
        $n     = \strlen($value);
        while ($p < $n && $value[$p] >= '0' && $value[$p] <= '9') {
            ++$p;
        }

        return (int)str_pad(substr($value, $start, 9), 9, '0', \STR_PAD_RIGHT);
    }

    private static function parseZoneName(string $value, int &$p): ?DateTimeZone
    {
        if (1 !== preg_match('/\G([A-Z]{3,5}|[+-][0-9]{2}(?:[0-9]{2})?)/', $value, $m, 0, $p)) {
            return null;
        }

        $p += \strlen($m[1]);
        $name = $m[1];
        if (self::UTC === $name) {
            return new DateTimeZone(self::UTC);
        }

        // timezone_open() answers false for an unknown zone, where the constructor would throw
        set_error_handler(static fn (): bool => true);
        try {
            $zone = timezone_open($name);
        } finally {
            restore_error_handler();
        }

        return false === $zone ? new DateTimeZone(self::UTC) : $zone;
    }

    private static function parseOffset(string $value, int &$p, string $std): ?DateTimeZone
    {
        if ('Z' === $std[0] && 'Z' === substr($value, $p, 1)) {
            ++$p;

            return new DateTimeZone(self::UTC);
        }

        $colon = str_contains($std, ':');
        $regex = match (true) {
            str_ends_with($std, '07')        => '/\G([+-])([0-9]{2})()()/',
            str_contains($std, '0000')       => '/\G([+-])([0-9]{2})([0-9]{2})([0-9]{2})/',
            str_ends_with($std, ':00:00')    => '/\G([+-])([0-9]{2}):([0-9]{2}):([0-9]{2})/',
            $colon                           => '/\G([+-])([0-9]{2}):([0-9]{2})()/',
            default                          => '/\G([+-])([0-9]{2})([0-9]{2})()/',
        };
        if (1 !== preg_match($regex, $value, $m, 0, $p)) {
            return null;
        }

        $p += \strlen($m[0]);
        $seconds = ((int)$m[2]) * 3600 + ((int)$m[3]) * 60 + (int)$m[4];
        if (0 === $seconds) {
            return new DateTimeZone(self::UTC);
        }

        $sign = $m[1];

        return new DateTimeZone(\sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60)));
    }
}
