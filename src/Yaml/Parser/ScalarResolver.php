<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Parser;

use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Resolves the implicit tag of a plain scalar exactly as go-yaml does for the reference yq: the YAML
 * 1.2 core schema plus the go-yaml extensions that show in yq output. Underscores are ignored inside
 * numbers, integers accept the 0x, 0o and 0b prefixes and a leading 0 for octal, values that fit an
 * unsigned 64-bit integer are ints, a lone "-0" is a float, dates and date-times are !!timestamp, and
 * a float that overflows a double stays a string.
 */
final readonly class ScalarResolver
{
    public const string TAG_MERGE = '!!merge';

    private const string FLOAT_PATTERN = '/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/D';

    private const string INT_PATTERN = '/^([-+]?)(?:0[xX]([0-9a-fA-F]+)|0[bB]([01]+)|0[oO]([0-7]+)|(0[0-7]*)|([1-9][0-9]*))$/D';

    private const string TIMESTAMP_PATTERN = '/^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})(?:(?:[Tt]([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})(?:[.,][0-9]+)?(?:Z|[-+]([0-9]{2}):([0-9]{2})))|(?: ([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})(?:[.,][0-9]+)?))?$/D';

    private function __construct()
    {
    }

    /**
     * The implicit tag of the plain scalar text.
     */
    public static function resolve(string $plain): string
    {
        if ('' === $plain) {
            return CoreSchema::TAG_NULL;
        }

        $first = $plain[0];
        if ($first >= '0' && $first <= '9' || '-' === $first || '+' === $first) {
            return self::resolveNumber($plain);
        }

        if ('.' === $first) {
            return match ($plain) {
                '.nan', '.NaN', '.NAN', '.inf', '.Inf', '.INF' => CoreSchema::TAG_FLOAT,
                default                                        => 1 === preg_match('/^\.[0-9]+(?:[eE][-+]?[0-9]+)?$/D', $plain) && self::floatFits($plain) ? CoreSchema::TAG_FLOAT : CoreSchema::TAG_STR,
            };
        }

        return match ($plain) {
            '~', 'null', 'Null', 'NULL'                       => CoreSchema::TAG_NULL,
            'true', 'True', 'TRUE', 'false', 'False', 'FALSE' => CoreSchema::TAG_BOOL,
            '<<'                                              => self::TAG_MERGE,
            default                                           => CoreSchema::TAG_STR,
        };
    }

    private static function resolveNumber(string $plain): string
    {
        // Fast path (benchmark yq:identity-medium): a plain decimal integer that cannot overflow and has no
        // leading zero, which is most numbers in real documents, needs none of the pattern matching below.
        $length = \strlen($plain);
        if ($length <= 18 && \strlen($plain) === strspn($plain, '0123456789') && ('0' !== $plain[0] || 1 === $length)) {
            return CoreSchema::TAG_INT;
        }

        if (match ($plain) {
            '+.inf', '+.Inf', '+.INF', '-.inf', '-.Inf', '-.INF' => true,
            default                                              => false,
        }) {
            return CoreSchema::TAG_FLOAT;
        }

        if (self::isTimestamp($plain)) {
            return CoreSchema::TAG_TIMESTAMP;
        }

        $stripped = str_contains($plain, '_') ? str_replace('_', '', $plain) : $plain;
        if (1 === preg_match(self::INT_PATTERN, $stripped, $m) && self::intFits($m)) {
            return '-0' === $stripped ? CoreSchema::TAG_FLOAT : CoreSchema::TAG_INT;
        }

        if (1 === preg_match(self::FLOAT_PATTERN, $stripped) && self::floatFits($stripped)) {
            return CoreSchema::TAG_FLOAT;
        }

        return CoreSchema::TAG_STR;
    }

    private static function floatFits(string $text): bool
    {
        return !is_infinite((float)$text);
    }

    /**
     * Whether the digits fit int64, or uint64 when unsigned.
     *
     * @param array<int, string> $m the INT_PATTERN groups
     */
    private static function intFits(array $m): bool
    {
        $sign = $m[1];
        if (isset($m[6]) && '' !== $m[6]) {
            return self::within(ltrim($m[6], '0'), $sign, '9223372036854775807', '9223372036854775808', '18446744073709551615');
        }

        if (isset($m[5]) && '' !== $m[5]) {
            return self::within(ltrim($m[5], '0'), $sign, '777777777777777777777', '1000000000000000000000', '1777777777777777777777');
        }

        if (isset($m[2]) && '' !== $m[2]) {
            return self::within(strtolower(ltrim($m[2], '0')), $sign, '7fffffffffffffff', '8000000000000000', 'ffffffffffffffff');
        }

        if (isset($m[3]) && '' !== $m[3]) {
            return self::within(ltrim($m[3], '0'), $sign, str_repeat('1', 63), '1' . str_repeat('0', 63), str_repeat('1', 64));
        }

        if (isset($m[4]) && '' !== $m[4]) {
            return self::within(ltrim($m[4], '0'), $sign, '777777777777777777777', '1000000000000000000000', '1777777777777777777777');
        }

        return true;
    }

    private static function within(string $digits, string $sign, string $positiveMax, string $negativeMax, string $unsignedMax): bool
    {
        $max = match ($sign) {
            '-'     => $negativeMax,
            '+'     => $positiveMax,
            default => $unsignedMax,
        };

        $length = \strlen($digits);
        $limit  = \strlen($max);

        return $length < $limit || ($length === $limit && strcmp($digits, $max) <= 0);
    }

    private static function isTimestamp(string $text): bool
    {
        if (\strlen($text) < 8 || 1 !== preg_match(self::TIMESTAMP_PATTERN, $text, $m)) {
            return false;
        }

        // preg_match omits trailing groups that did not participate; fill them with '' so every index below exists.
        $m += array_fill(0, 12, '');

        $year  = (int)$m[1];
        $month = (int)$m[2];
        $day   = (int)$m[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return false;
        }

        $clocks = [
            [$m[4], $m[5], $m[6]],
            [$m[9], $m[10], $m[11]],
        ];
        foreach ($clocks as [$hour, $minute, $second]) {
            if ('' !== $hour && ((int)$hour > 23 || (int)$minute > 59 || (int)$second > 59)) {
                return false;
            }
        }

        $zoneHour = $m[7];

        return '' === $zoneHour || (int)$zoneHour <= 24 && (int)$m[8] <= 59;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if (2 === $month) {
            return 0 === $year % 4 && (0 !== $year % 100 || 0 === $year % 400) ? 29 : 28;
        }

        return \in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }
}
