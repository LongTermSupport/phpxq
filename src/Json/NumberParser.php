<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\Codec\DecimalLiteral;
use LTS\PhpXq\Json\Codec\NumberFormatter;

/**
 * Number literal text to the value model, shared by the JSON decoder and the jq compiler so that the
 * literal-preservation rules (architecture.md, number semantics) are defined once.
 *
 * The decision mirrors jq 1.8 with decNumber: the literal survives unchanged until arithmetic touches it.
 * A literal becomes a plain int/float when printing the double gives the same text jq would print for the
 * literal (decNumber's canonical form: `1.5`, `300`, `-0.25`); anything else, such as `1.0`, `1e2`,
 * `100000000000000000000`, `1E+1000` or any integer beyond 2^53, becomes a {@see PreciseNumber} carrying that
 * canonical text.
 *
 * @api
 */
final readonly class NumberParser
{
    private const int TWO_TO_53 = 9007199254740992;

    private const int MAX_ADJUSTED_EXPONENT = 999_999_999;

    private function __construct()
    {
    }

    /**
     * @param string $literal a JSON/jq number literal, e.g. `1`, `-0`, `1.5e3`, `13911860366432393`
     *
     * @return int|float|PreciseNumber int when integral and |n| <= 2^53; float when the double prints
     *                                 identically to the literal; PreciseNumber otherwise
     *
     * @throws InvalidArgumentException when $literal is not a number token
     */
    public static function parse(string $literal): int|float|PreciseNumber
    {
        $parsed = self::tryParse($literal);
        if (null === $parsed) {
            throw new InvalidArgumentException('Invalid numeric literal: ' . $literal);
        }

        return $parsed;
    }

    /**
     * Unary minus on a preserved literal: jq negates the decimal, so the digits survive ("-1.000" becomes
     * "1.000", "1E+1000" becomes "-1E+1000").
     */
    public static function negate(PreciseNumber $number): PreciseNumber
    {
        $literal = str_starts_with($number->literal, '-') ? substr($number->literal, 1) : '-' . $number->literal;

        return new PreciseNumber(-$number->value, $literal);
    }

    /**
     * The absolute value of a preserved literal, keeping its digits.
     */
    public static function abs(PreciseNumber $number): PreciseNumber
    {
        if (!str_starts_with($number->literal, '-')) {
            return $number;
        }

        return new PreciseNumber(abs($number->value), substr($number->literal, 1));
    }

    /**
     * Like {@see self::parse} but returns null for text that is not a number. Besides decimal literals it
     * accepts nan and infinity in any case with an optional sign (jq's decNumber based parser does).
     */
    public static function tryParse(string $literal): int|float|PreciseNumber|null
    {
        $length = \strlen($literal);
        if ($length <= 15 && ctype_digit($literal)) {
            return (int)$literal;
        }

        if ($length > 1 && $length <= 16 && '-' === $literal[0] && ctype_digit(substr($literal, 1))) {
            $magnitude = (int)substr($literal, 1);

            return 0 === $magnitude ? -0.0 : -$magnitude;
        }

        $parts = DecimalLiteral::parse($literal);
        if (null === $parts) {
            return self::nonFinite($literal);
        }

        [$negative, $digits, $exponent] = $parts;

        return '0' === $digits
            ? self::zero($negative, $exponent)
            : self::nonZero($negative, $digits, $exponent);
    }

    private static function nonFinite(string $literal): ?float
    {
        return match (strtolower($literal)) {
            'nan', '+nan', '-nan'                  => \NAN,
            'inf', '+inf', 'infinity', '+infinity' => \INF,
            '-inf', '-infinity'                    => -\INF,
            default                                => null,
        };
    }

    private static function zero(bool $negative, int $exponent): int|float|PreciseNumber
    {
        $value     = $negative ? -0.0 : 0.0;
        $canonical = DecimalLiteral::canonical($negative, '0', $exponent);
        if ('0' === $canonical) {
            return 0;
        }

        if ('-0' === $canonical) {
            return $value;
        }

        return new PreciseNumber($value, $canonical);
    }

    private static function nonZero(bool $negative, string $digits, int $exponent): int|float|PreciseNumber
    {
        $count    = \strlen($digits);
        $adjusted = $exponent + $count - 1;
        if ($adjusted > self::MAX_ADJUSTED_EXPONENT) {
            return $negative ? -\INF : \INF;
        }

        if ($adjusted < -self::MAX_ADJUSTED_EXPONENT) {
            return $negative ? -0.0 : 0.0;
        }

        if (0 === $exponent && $count <= 16) {
            $integer = (int)$digits;
            if ($integer <= self::TWO_TO_53) {
                return $negative ? -$integer : $integer;
            }
        }

        $value = (float)($digits . 'E' . $exponent);
        if ($negative) {
            $value = -$value;
        }

        // A fraction with at most 15 significant digits, no trailing zero and a decimal point within reach
        // prints identically through the shortest round-trip double formatting.
        if ($exponent < 0 && $count <= 15 && '0' !== $digits[$count - 1] && $adjusted >= -4) {
            return $value;
        }

        $canonical = DecimalLiteral::canonical($negative, $digits, $exponent);
        if ($exponent < 0 && NumberFormatter::format($value) === $canonical) {
            return $value;
        }

        return new PreciseNumber($value, $canonical);
    }
}
