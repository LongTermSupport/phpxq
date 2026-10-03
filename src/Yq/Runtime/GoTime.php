<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Go's `time` layouts and durations on top of DateTimeImmutable: formatting and parsing with
 * reference-time layouts such as `Monday, 02-Jan-06 at 3:04PM MST`, and `ParseDuration` strings.
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

    private const array STD_LONG_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** @var array<string, list<array{string, string}>> */
    private static array $layouts = [];

    private function __construct()
    {
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
                $tokens[] = ['lit', $lit];
                $lit      = '';
            }

            $tokens[] = ['std', $std];
            $i += \strlen($std);
        }

        if ('' !== $lit) {
            $tokens[] = ['lit', $lit];
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
        $next = $layout[$i + 1] ?? '';
        switch ($c) {
            case 'J':
                if ('Jan' === substr($layout, $i, 3)) {
                    return 'January' === substr($layout, $i, 7) ? 'January' : 'Jan';
                }

                return null;

            case 'M':
                if ('Mon' === substr($layout, $i, 3)) {
                    return 'Monday' === substr($layout, $i, 6) ? 'Monday' : 'Mon';
                }

                return 'MST' === substr($layout, $i, 3) ? 'MST' : null;

            case '0':
                if ($next >= '1' && $next <= '6') {
                    return '0' . $next;
                }

                return '002' === substr($layout, $i, 3) ? '002' : null;

            case '1':
                return '5' === $next ? '15' : '1';

            case '2':
                return '2006' === substr($layout, $i, 4) ? '2006' : '2';

            case '_':
                if ('2' === $next) {
                    return '2006' === substr($layout, $i + 1, 4) ? null : '_2';
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
                foreach (['-070000', '-07:00:00', '-0700', '-07:00', '-07'] as $std) {
                    if (substr($layout, $i, \strlen($std)) === $std) {
                        return $std;
                    }
                }

                return null;

            case 'Z':
                foreach (['Z070000', 'Z07:00:00', 'Z0700', 'Z07:00', 'Z07'] as $std) {
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

                    $after = $layout[$j] ?? '';
                    if ($after < '0' || $after > '9') {
                        return substr($layout, $i, $j - $i);
                    }
                }

                return null;

            default:
                return null;
        }
    }

    public static function format(DateTimeImmutable $time, string $layout): string
    {
        $out = '';
        foreach (self::tokens($layout) as [$kind, $text]) {
            $out .= 'lit' === $kind ? $text : self::formatStd($time, $text);
        }

        return $out;
    }

    private static function formatStd(DateTimeImmutable $t, string $std): string
    {
        switch ($std) {
            case '2006':
                return $t->format('Y');
            case '06':
                return $t->format('y');
            case '01':
                return $t->format('m');
            case '1':
                return $t->format('n');
            case 'Jan':
                return $t->format('M');
            case 'January':
                return $t->format('F');
            case '02':
                return $t->format('d');
            case '2':
                return $t->format('j');
            case '_2':
                return str_pad($t->format('j'), 2, ' ', \STR_PAD_LEFT);
            case '002':
                return str_pad((string) ((int) $t->format('z') + 1), 3, '0', \STR_PAD_LEFT);
            case '15':
                return $t->format('H');
            case '03':
                return $t->format('h');
            case '3':
                return $t->format('g');
            case '04':
                return $t->format('i');
            case '4':
                return (string) (int) $t->format('i');
            case '05':
                return $t->format('s');
            case '5':
                return (string) (int) $t->format('s');
            case 'PM':
                return $t->format('A');
            case 'pm':
                return $t->format('a');
            case 'Mon':
                return $t->format('D');
            case 'Monday':
                return $t->format('l');
            case 'MST':
                return self::zoneName($t);
            case '-0700':
                return $t->format('O');
            case '-07:00':
                return $t->format('P');
            case '-07':
                return substr($t->format('O'), 0, 3);
            case '-070000':
                return $t->format('O') . '00';
            case '-07:00:00':
                return $t->format('P') . ':00';
            case 'Z0700':
                return 0 === $t->getOffset() ? 'Z' : $t->format('O');
            case 'Z07:00':
                return 0 === $t->getOffset() ? 'Z' : $t->format('P');
            case 'Z07':
                return 0 === $t->getOffset() ? 'Z' : substr($t->format('O'), 0, 3);
            case 'Z070000':
                return 0 === $t->getOffset() ? 'Z' : $t->format('O') . '00';
            case 'Z07:00:00':
                return 0 === $t->getOffset() ? 'Z' : $t->format('P') . ':00';
            default:
                return self::formatFraction($t, $std);
        }
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
        if ('' !== $name && ('+' === $name[0] || '-' === $name[0])) {
            return 0 === $t->getOffset() ? 'UTC' : substr($t->format('O'), 0, 3);
        }

        return 'Z' === $name ? 'UTC' : $name;
    }

    /**
     * Parses `$value` with a Go layout; null when it does not match.
     */
    public static function parse(string $layout, string $value): ?DateTimeImmutable
    {
        $p  = 0;
        $n  = \strlen($value);
        $y  = 0;
        $mo = 1;
        $d  = 1;
        $h  = 0;
        $mi = 0;
        $s  = 0;
        $ns = 0;
        $pm = null;
        $tz = null;
        $tokens = self::tokens($layout);
        $count  = \count($tokens);
        foreach ($tokens as $index => [$kind, $text]) {
            if ('lit' === $kind) {
                if (substr($value, $p, \strlen($text)) !== $text) {
                    return null;
                }

                $p += \strlen($text);

                continue;
            }

            switch ($text) {
                case '2006':
                    $y = self::digits($value, $p, 4, 4);
                    break;
                case '06':
                    $y = self::digits($value, $p, 2, 2);
                    if (null !== $y) {
                        $y += $y >= 69 ? 1900 : 2000;
                    }

                    break;
                case '01':
                    $mo = self::digits($value, $p, 2, 2);
                    break;
                case '1':
                    $mo = self::digits($value, $p, 1, 2);
                    break;
                case 'Jan':
                case 'January':
                    $mo = self::monthName($value, $p, 'Jan' === $text);
                    break;
                case '02':
                    $d = self::digits($value, $p, 2, 2);
                    break;
                case '2':
                    $d = self::digits($value, $p, 1, 2);
                    break;
                case '_2':
                    if (' ' === ($value[$p] ?? '')) {
                        ++$p;
                    }

                    $d = self::digits($value, $p, 1, 2);
                    break;
                case '002':
                    $yday = self::digits($value, $p, 3, 3);
                    if (null === $yday) {
                        return null;
                    }

                    $mo = 1;
                    $d  = $yday;
                    break;
                case '15':
                    $h = self::digits($value, $p, 2, 2);
                    break;
                case '03':
                    $h = self::digits($value, $p, 2, 2);
                    break;
                case '3':
                    $h = self::digits($value, $p, 1, 2);
                    break;
                case '04':
                    $mi = self::digits($value, $p, 2, 2);
                    break;
                case '4':
                    $mi = self::digits($value, $p, 1, 2);
                    break;
                case '05':
                case '5':
                    $s = self::digits($value, $p, '5' === $text ? 1 : 2, 2);
                    if (null === $s) {
                        return null;
                    }

                    $nextIsFraction = isset($tokens[$index + 1]) && 'std' === $tokens[$index + 1][0] && \in_array($tokens[$index + 1][1][0], ['.', ','], true);
                    if (!$nextIsFraction && '.' === ($value[$p] ?? '') && $p + 1 < $n && ctype_digit($value[$p + 1])) {
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
                case 'Mon':
                case 'Monday':
                    $len = 0;
                    while ($p + $len < $n && ctype_alpha($value[$p + $len])) {
                        ++$len;
                    }

                    if ($len < 3) {
                        return null;
                    }

                    $p += $len;
                    break;
                case 'MST':
                    $tz = self::parseZoneName($value, $p);
                    if (null === $tz) {
                        return null;
                    }

                    break;
                case '-0700':
                case '-07:00':
                case '-07':
                case '-070000':
                case '-07:00:00':
                case 'Z0700':
                case 'Z07:00':
                case 'Z07':
                case 'Z070000':
                case 'Z07:00:00':
                    $tz = self::parseOffset($value, $p, $text);
                    if (null === $tz) {
                        return null;
                    }

                    break;
                default:
                    if ('.' !== $text[0] && ',' !== $text[0]) {
                        return null;
                    }

                    if (($value[$p] ?? '') !== $text[0] && !('.' === ($value[$p] ?? '') && ',' === $text[0])) {
                        return null;
                    }

                    $ns = self::fraction($value, $p);
                    break;
            }

            if (null === $y || null === $mo || null === $d || null === $h || null === $mi || null === $s) {
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

        $zone = $tz ?? new DateTimeZone('UTC');
        if ($d < 1 || $d > 31 || ($y > 0 && !checkdate($mo, $d, $y))) {
            return null;
        }

        try {
            return new DateTimeImmutable('now', $zone)->setDate($y, $mo, $d)->setTime($h, $mi, $s, intdiv($ns, 1000));
        } catch (Exception) {
            return null;
        }
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

        $number = (int) substr($value, $p, $len);
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

        return (int) str_pad(substr($value, $start, 9), 9, '0', \STR_PAD_RIGHT);
    }

    private static function parseZoneName(string $value, int &$p): ?DateTimeZone
    {
        if (1 !== preg_match('/\G([A-Z]{3,5}|[+-][0-9]{2}(?:[0-9]{2})?)/', $value, $m, 0, $p)) {
            return null;
        }

        $p += \strlen($m[1]);
        $name = $m[1];
        if ('UTC' === $name || 'GMT' === $name) {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }

    private static function parseOffset(string $value, int &$p, string $std): ?DateTimeZone
    {
        if ('Z' === $std[0] && 'Z' === ($value[$p] ?? '')) {
            ++$p;

            return new DateTimeZone('UTC');
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
        $seconds = ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (int) $m[4];
        if (0 === $seconds) {
            return new DateTimeZone('UTC');
        }

        $sign = $m[1];

        return new DateTimeZone(\sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60)));
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

            $total += (float) $m[1] * self::UNITS_NS[$m[2]];
            $rest = substr($rest, \strlen($m[0]));
        }

        return $sign * (int) round($total);
    }

    /**
     * Adds nanoseconds (negative to subtract) to a time.
     */
    public static function addNanos(DateTimeImmutable $time, int $nanos): DateTimeImmutable
    {
        $micro = (int) $time->format('u') + intdiv($nanos % 1000000000, 1000);
        $secs  = intdiv($nanos, 1000000000);
        if ($micro >= 1000000) {
            $micro -= 1000000;
            ++$secs;
        } elseif ($micro < 0) {
            $micro += 1000000;
            --$secs;
        }

        $moved = $time->setTimestamp($time->getTimestamp() + $secs);

        return $moved->setTime((int) $moved->format('H'), (int) $moved->format('i'), (int) $moved->format('s'), $micro);
    }
}
