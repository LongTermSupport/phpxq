<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json\Codec;

/**
 * Doubles to text exactly as jq 1.8's jvp_dtoa_fmt prints them: the shortest digit string that round trips,
 * laid out plainly while the decimal point is within reach and in scientific notation (signed exponent of
 * at least two digits) otherwise. nan prints null and infinities print as the largest finite double.
 *
 * @internal
 */
final readonly class NumberFormatter
{
    private const string MAX_DOUBLE = '1.7976931348623157e+308';

    private function __construct()
    {
    }

    public static function format(float $value): string
    {
        if (is_nan($value)) {
            return 'null';
        }

        if (is_infinite($value)) {
            return $value > 0 ? self::MAX_DOUBLE : '-' . self::MAX_DOUBLE;
        }

        if (0.0 === $value) {
            return fdiv(1.0, $value) < 0 ? '-0' : '0';
        }

        if (abs($value) < 1.0e15 && floor($value) === $value) {
            return (string)(int)$value;
        }

        // PHP prints the shortest round-trip digits (serialize_precision -1). A fraction without an exponent
        // is already laid out as jq lays it out: both switch to exponents below 1e-4.
        $repr = var_export($value, true);
        $sign = '';
        if ('-' === $repr[0]) {
            $sign = '-';
            $repr = substr($repr, 1);
        }

        $exponentAt = strpos($repr, 'E');
        if (false !== $exponentAt) {
            $digits = rtrim(str_replace('.', '', substr($repr, 0, $exponentAt)), '0');

            return $sign . self::layout($digits, (int)substr($repr, $exponentAt + 1) + 1);
        }

        if (!str_ends_with($repr, '.0')) {
            return $sign . $repr;
        }

        // integral beyond 1e15, which PHP still prints in full with a ".0"
        $integral = substr($repr, 0, -2);

        return $sign . self::layout(rtrim($integral, '0'), \strlen($integral));
    }

    private static function layout(string $digits, int $decpt): string
    {
        $count = \strlen($digits);
        if ($decpt <= -4 || $decpt > $count + 15) {
            $exponent = $decpt - 1;
            $mantissa = $digits[0] . ($count > 1 ? '.' . substr($digits, 1) : '');

            return $mantissa . 'e' . ($exponent < 0 ? '-' : '+') . str_pad((string)abs($exponent), 2, '0', \STR_PAD_LEFT);
        }

        if ($decpt <= 0) {
            return '0.' . str_repeat('0', -$decpt) . $digits;
        }

        if ($decpt >= $count) {
            return $digits . str_repeat('0', $decpt - $count);
        }

        return substr($digits, 0, $decpt) . '.' . substr($digits, $decpt);
    }
}
