<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Json\Codec\Utf8;

/**
 * UTF-8 text helpers on strings that are always valid UTF-8 (the value model guarantees it): codepoint
 * counts and offsets, explode/implode, jq's whitespace trimming (ASCII controls 9 to 13 and the Unicode
 * White_Space characters).
 *
 * @internal
 */
final readonly class Unicode
{
    private const string REPLACEMENT = "\u{fffd}";

    private const string TRIM_CLASS = '[\x09-\x0d\x20\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]';

    private function __construct()
    {
    }

    public static function length(string $text): int
    {
        return \strlen($text) - self::continuations($text);
    }

    /**
     * @return list<int> invalid UTF-8, which the value model should never hold, decodes as jq would decode it
     *                   on input: each invalid sequence is one U+FFFD, so the decoder never reads past the end
     */
    public static function codepoints(string $text): array
    {
        $text  = Utf8::sanitize($text);
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

        return Utf8::encode($codepoint);
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

    private static function continuations(string $text): int
    {
        $count = preg_match_all('/[\x80-\xBF]/', $text);

        return false === $count ? 0 : $count;
    }
}
