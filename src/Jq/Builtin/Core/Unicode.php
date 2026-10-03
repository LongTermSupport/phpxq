<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

/**
 * UTF-8 text helpers on strings that are always valid UTF-8 (the value model guarantees it): codepoint
 * counts and offsets, explode/implode, jq's whitespace trimming.
 *
 * @internal
 */
final class Unicode
{
    private const string REPLACEMENT = "\u{fffd}";

    /**
     * Whitespace as jq's trim sees it: ASCII controls 9 to 13 and the Unicode White_Space characters.
     */
    private const string TRIM_CLASS = '[\x09-\x0d\x20\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]';

    private function __construct()
    {
    }

    public static function length(string $text): int
    {
        return \strlen($text) - self::continuations($text);
    }

    /**
     * @return list<int>
     */
    public static function codepoints(string $text): array
    {
        $bytes = \strlen($text);
        $out   = [];
        for ($i = 0; $i < $bytes; ++$i) {
            $first = \ord($text[$i]);
            if ($first < 0x80) {
                $out[] = $first;

                continue;
            }

            if ($first < 0xE0) {
                $out[] = (($first & 0x1F) << 6) | (\ord($text[$i + 1]) & 0x3F);
                ++$i;

                continue;
            }

            if ($first < 0xF0) {
                $out[] = (($first & 0x0F) << 12) | ((\ord($text[$i + 1]) & 0x3F) << 6) | (\ord($text[$i + 2]) & 0x3F);
                $i += 2;

                continue;
            }

            $out[] = (($first & 0x07) << 18)
                | ((\ord($text[$i + 1]) & 0x3F) << 12)
                | ((\ord($text[$i + 2]) & 0x3F) << 6)
                | (\ord($text[$i + 3]) & 0x3F);
            $i += 3;
        }

        return $out;
    }

    /**
     * UTF-8 of one codepoint; surrogates, negative values and values above U+10FFFF give U+FFFD.
     */
    public static function encode(int $codepoint): string
    {
        if ($codepoint < 0) {
            return self::REPLACEMENT;
        }

        if ($codepoint < 0x80) {
            return \chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return \chr(0xC0 | ($codepoint >> 6)) . \chr(0x80 | ($codepoint & 0x3F));
        }

        if (($codepoint >= 0xD800 && $codepoint <= 0xDFFF) || $codepoint > 0x10FFFF) {
            return self::REPLACEMENT;
        }

        if ($codepoint < 0x10000) {
            return \chr(0xE0 | ($codepoint >> 12)) . \chr(0x80 | (($codepoint >> 6) & 0x3F)) . \chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18))
            . \chr(0x80 | (($codepoint >> 12) & 0x3F))
            . \chr(0x80 | (($codepoint >> 6) & 0x3F))
            . \chr(0x80 | ($codepoint & 0x3F));
    }

    /**
     * The characters of a string, one string each.
     *
     * @return list<string>
     */
    public static function characters(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        $parts = preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $parts ? [] : $parts;
    }

    /**
     * Number of codepoints in the first $bytes bytes of $text ($bytes sits on a character boundary).
     */
    public static function offsetOf(string $text, int $bytes): int
    {
        return $bytes - self::continuations(substr($text, 0, $bytes));
    }

    public static function trim(string $text, bool $left, bool $right): string
    {
        $pattern = self::TRIM_CLASS;
        if ($left) {
            $text = (string)preg_replace('/^' . $pattern . '+/u', '', $text);
        }

        if ($right) {
            return (string)preg_replace('/' . $pattern . '+$/Du', '', $text);
        }

        return $text;
    }

    /**
     * Characters from codepoint $from (inclusive) up to $to (exclusive), clamped like jq's string slices.
     */
    public static function slice(string $text, int $from, int $to): string
    {
        $length = self::length($text);
        $from   = max(0, min($length, $from));
        $to     = max($from, min($length, $to));
        if (0 === $from && $to === $length) {
            return $text;
        }

        if (\strlen($text) === $length) {
            return substr($text, $from, $to - $from);
        }

        return implode('', \array_slice(self::characters($text), $from, $to - $from));
    }

    private static function continuations(string $text): int
    {
        $count = preg_match_all('/[\x80-\xBF]/', $text);

        return false === $count ? 0 : $count;
    }
}
