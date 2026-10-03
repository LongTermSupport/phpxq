<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json\Codec;

/**
 * Exact decimal literals as jq's decNumber handles them: parsing a number token into sign, coefficient and
 * exponent, printing the canonical "to-scientific-string" form (1.000 stays 1.000, 100e-2 becomes 1.00,
 * 1e2 becomes 1E+2), and comparing two literals without going through a double.
 *
 * @internal
 */
final class DecimalLiteral
{
    private const int EXPONENT_CLAMP = 100_000_000_000;

    private function __construct()
    {
    }

    /**
     * Split a decimal token (`[+-]?(digits[.digits?]|.digits)([eE][+-]?digits)?`) into its parts.
     *
     * @return ?array{bool, string, int} negative, coefficient without leading zeros ("0" for zero), exponent
     */
    public static function parse(string $literal): ?array
    {
        if (1 !== preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?)(\d+))?$/D', $literal, $m)) {
            return null;
        }

        $integral = $m[2];
        $fraction = $m[3] ?? '';
        if ('' === $integral && '' === $fraction) {
            return null;
        }

        $digits = ltrim($integral . $fraction, '0');
        if ('' === $digits) {
            $digits = '0';
        }

        $exponent = 0;
        if (isset($m[5])) {
            $expDigits = ltrim($m[5], '0');
            $exponent  = \strlen($expDigits) > 11 ? self::EXPONENT_CLAMP : (int)$expDigits;
            if ('-' === $m[4]) {
                $exponent = -$exponent;
            }
        }

        return ['-' === $m[1], $digits, $exponent - \strlen($fraction)];
    }

    /**
     * decNumber's to-scientific-string.
     */
    public static function canonical(bool $negative, string $digits, int $exponent): string
    {
        $count    = \strlen($digits);
        $adjusted = $exponent + $count - 1;
        $sign     = $negative ? '-' : '';
        if ($exponent <= 0 && $adjusted >= -6) {
            if (0 === $exponent) {
                return $sign . $digits;
            }

            $point = $count + $exponent;
            if ($point > 0) {
                return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
            }

            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }

        return $sign . $digits[0] . ($count > 1 ? '.' . substr($digits, 1) : '') . 'E' . ($adjusted < 0 ? '-' : '+') . abs($adjusted);
    }

    /**
     * Numeric comparison of two decimal literals: -1, 0 or 1. Both must be valid (see {@see self::parse}).
     */
    public static function compare(string $left, string $right): int
    {
        $a = self::parse($left);
        $b = self::parse($right);
        if (null === $a || null === $b) {
            return 0;
        }

        [$leftNegative, $leftDigits, $leftExponent]    = $a;
        [$rightNegative, $rightDigits, $rightExponent] = $b;

        $leftZero  = '0' === $leftDigits;
        $rightZero = '0' === $rightDigits;
        if ($leftZero && $rightZero) {
            return 0;
        }

        if ($leftZero) {
            return $rightNegative ? 1 : -1;
        }

        if ($rightZero) {
            return $leftNegative ? -1 : 1;
        }

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $magnitude = self::compareMagnitude($leftDigits, $leftExponent, $rightDigits, $rightExponent);

        return $leftNegative ? -$magnitude : $magnitude;
    }

    private static function compareMagnitude(string $leftDigits, int $leftExponent, string $rightDigits, int $rightExponent): int
    {
        $leftAdjusted  = $leftExponent  + \strlen($leftDigits);
        $rightAdjusted = $rightExponent + \strlen($rightDigits);
        if ($leftAdjusted !== $rightAdjusted) {
            return $leftAdjusted <=> $rightAdjusted;
        }

        $width = max(\strlen($leftDigits), \strlen($rightDigits));

        return strcmp(str_pad($leftDigits, $width, '0'), str_pad($rightDigits, $width, '0')) <=> 0;
    }
}
