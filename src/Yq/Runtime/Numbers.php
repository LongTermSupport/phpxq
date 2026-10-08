<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Number text <-> PHP number conversion with the formatting the reference (Go) uses: integers verbatim,
 * floats as the shortest decimal that round-trips without an exponent, `+Inf`, `-Inf` and `NaN`.
 */
final readonly class Numbers
{
    private const string PARSE_INT_ERROR = 'strconv.ParseInt: parsing "%s": %s';

    private function __construct()
    {
    }

    /**
     * Parses the text of a YAML number: decimal, `0x` hex, `0o` octal, `0b` binary, floats with exponent,
     * `.inf`, `.nan`. Returns null when the text is not a number.
     */
    public static function parse(string $text): int|float|null
    {
        $length = \strlen($text);
        if (0 === $length) {
            return null;
        }

        $first = $text[0];
        if ('-' !== $first && '+' !== $first && '.' !== $first && ($first < '0' || $first > '9') && 'I' !== $first && 'N' !== $first && 'i' !== $first && 'n' !== $first) {
            return null;
        }

        if (1 === preg_match('/^[-+]?[0-9]+$/D', $text)) {
            $int = (int)$text;
            if ((string)$int === ltrim($text, '+') || (string)$int === self::normalise($text)) {
                return $int;
            }

            return (float)$text;
        }

        if (1 === preg_match('/^([-+]?)0x([0-9a-fA-F]+)$/D', $text, $m)) {
            $value = hexdec($m[2]);

            return '-' === $m[1] ? -$value : $value;
        }

        if (1 === preg_match('/^([-+]?)0o([0-7]+)$/D', $text, $m)) {
            $value = octdec($m[2]);

            return '-' === $m[1] ? -$value : $value;
        }

        if (1 === preg_match('/^([-+]?)0b([01]+)$/D', $text, $m)) {
            $value = bindec($m[2]);

            return '-' === $m[1] ? -$value : $value;
        }

        if (1 === preg_match('/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/D', $text)) {
            return (float)$text;
        }

        return match ($text) {
            '.inf', '.Inf', '.INF', '+.inf', '+.Inf', '+.INF', '+Inf', 'Inf' => \INF,
            '-.inf', '-.Inf', '-.INF', '-Inf'                                => -\INF,
            '.nan', '.NaN', '.NAN', 'NaN'                                    => \NAN,
            default                                                          => null,
        };
    }

    /**
     * The number a node holds, or null when it is not a number node.
     */
    public static function of(Node $node): int|float|null
    {
        if (NodeKindEnum::Scalar !== $node->kind) {
            return null;
        }

        $tag = NodeOps::effectiveTag($node);
        if (CoreSchema::TAG_INT !== $tag && CoreSchema::TAG_FLOAT !== $tag) {
            return null;
        }

        return self::parse($node->value);
    }

    /**
     * Go's `strconv.FormatFloat(f, 'f', -1, 64)` with `+Inf`, `-Inf`, `NaN`.
     */
    public static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        $text = var_export($value, true);
        if (false !== stripos($text, 'e')) {
            return self::expandExponent($text);
        }

        if (str_ends_with($text, '.0')) {
            return substr($text, 0, -2);
        }

        return $text;
    }

    /**
     * The int a number node holds where yq requires one (a slice bound, a function's count argument), or null
     * when the node is not a number. Go yq parses the text with `strconv.ParseInt`, so any other number is
     * its error: a fraction, an exponent, infinity or NaN is invalid syntax, an integer past the 64-bit range
     * is out of range.
     *
     * @throws EvaluationException for a number that is not an int
     */
    public static function intOf(Node $node): ?int
    {
        $number = self::of($node);
        if (null === $number || \is_int($number)) {
            return $number;
        }

        $reason = 1 === preg_match('/^[-+]?[0-9]+$/D', $node->value) ? 'value out of range' : 'invalid syntax';

        throw new EvaluationException(\sprintf(self::PARSE_INT_ERROR, $node->value, $reason));
    }

    public static function tagOf(int|float $value): string
    {
        return \is_int($value) ? CoreSchema::TAG_INT : CoreSchema::TAG_FLOAT;
    }

    private static function normalise(string $text): string
    {
        $negative = '-' === $text[0];
        $digits   = ltrim($text, '+-');
        $digits   = ltrim($digits, '0');
        if ('' === $digits) {
            return '0';
        }

        return ($negative ? '-' : '') . $digits;
    }

    private static function expandExponent(string $text): string
    {
        [$mantissa, $exponent] = explode('E', strtoupper($text));
        $exponent              = (int)$exponent;
        $negative              = str_starts_with($mantissa, '-');
        $mantissa              = ltrim($mantissa, '-');
        $dot                   = strpos($mantissa, '.');
        $pointAt               = false === $dot ? \strlen($mantissa) : $dot;
        $digits                = str_replace('.', '', $mantissa);
        $pointAt += $exponent;
        if ($pointAt <= 0) {
            $out = '0.' . str_repeat('0', -$pointAt) . $digits;
        } elseif ($pointAt >= \strlen($digits)) {
            $out = $digits . str_repeat('0', $pointAt - \strlen($digits));
        } else {
            $out = substr($digits, 0, $pointAt) . '.' . substr($digits, $pointAt);
        }

        if (str_contains($out, '.')) {
            $out = rtrim(rtrim($out, '0'), '.');
        }

        return ($negative ? '-' : '') . $out;
    }
}
