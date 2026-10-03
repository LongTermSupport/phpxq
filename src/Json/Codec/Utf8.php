<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json\Codec;

/**
 * UTF-8 helpers with jq's invalid-sequence policy: every invalid sequence becomes one U+FFFD.
 *
 * @internal
 */
final class Utf8
{
    private const string REPLACEMENT = "\u{fffd}";

    private function __construct()
    {
    }

    /**
     * Replace invalid UTF-8 with U+FFFD the way jq's jvp_utf8_next does: an invalid lead byte, an overlong
     * form, a surrogate, a value above U+10FFFF, or a truncated sequence each yield one replacement for the
     * bytes consumed.
     */
    public static function sanitize(string $text): string
    {
        if (1 === preg_match('//u', $text)) {
            return $text;
        }

        $length = \strlen($text);
        $out    = '';
        $i      = 0;
        while ($i < $length) {
            $first = \ord($text[$i]);
            if ($first < 0x80) {
                $out .= $text[$i];
                ++$i;

                continue;
            }

            $needed = self::leadLength($first);
            if (0 === $needed) {
                $out .= self::REPLACEMENT;
                ++$i;

                continue;
            }

            if ($i + $needed > $length) {
                $out .= self::REPLACEMENT;
                $i    = $length;

                continue;
            }

            $codepoint = $first & (0x7F >> $needed);
            $consumed  = $needed;
            for ($k = 1; $k < $needed; ++$k) {
                $byte = \ord($text[$i + $k]);
                if (0x80 !== ($byte & 0xC0)) {
                    $codepoint = -1;
                    $consumed  = $k;

                    break;
                }

                $codepoint = ($codepoint << 6) | ($byte & 0x3F);
            }

            if ($codepoint >= 0 && !self::validCodepoint($codepoint, $consumed)) {
                $codepoint = -1;
            }

            $out .= $codepoint < 0 ? self::REPLACEMENT : substr($text, $i, $consumed);
            $i   += $consumed;
        }

        return $out;
    }

    /**
     * UTF-8 encoding of one codepoint; surrogates and values above U+10FFFF encode as U+FFFD.
     */
    public static function encode(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return \chr(max(0, $codepoint));
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
     * The codepoint of one complete, valid UTF-8 character.
     */
    public static function codepoint(string $character): int
    {
        $first = \ord($character[0]);
        if ($first < 0x80) {
            return $first;
        }

        if ($first < 0xE0) {
            return (($first & 0x1F) << 6) | (\ord($character[1]) & 0x3F);
        }

        if ($first < 0xF0) {
            return (($first & 0x0F) << 12) | ((\ord($character[1]) & 0x3F) << 6) | (\ord($character[2]) & 0x3F);
        }

        return (($first & 0x07) << 18)
            | ((\ord($character[1]) & 0x3F) << 12)
            | ((\ord($character[2]) & 0x3F) << 6)
            | (\ord($character[3]) & 0x3F);
    }

    private static function leadLength(int $byte): int
    {
        if ($byte >= 0xC2 && $byte <= 0xDF) {
            return 2;
        }

        if ($byte >= 0xE0 && $byte <= 0xEF) {
            return 3;
        }

        if ($byte >= 0xF0 && $byte <= 0xF4) {
            return 4;
        }

        return 0;
    }

    private static function validCodepoint(int $codepoint, int $length): bool
    {
        $minimum = match ($length) {
            2       => 0x80,
            3       => 0x800,
            4       => 0x10000,
            default => 0,
        };

        return $codepoint >= $minimum
            && $codepoint <= 0x10FFFF
            && ($codepoint < 0xD800 || $codepoint > 0xDFFF);
    }
}
