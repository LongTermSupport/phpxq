<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * Codepoint-indexed string helpers over UTF-8 without mbstring. Strings in the value model are always valid
 * UTF-8.
 *
 * @internal
 */
final readonly class Text
{
    private function __construct()
    {
    }

    public static function isAscii(string $text): bool
    {
        return 0 === preg_match('/[\x80-\xFF]/', $text);
    }

    /**
     * Number of codepoints.
     */
    public static function length(string $text): int
    {
        $bytes = \strlen($text);
        if ($bytes < 2) {
            return $bytes;
        }

        return $bytes - (int)preg_match_all('/[\x80-\xBF]/', $text);
    }

    /**
     * The codepoints [$start, $end) as a string; the bounds are already clamped to 0..length.
     */
    public static function slice(string $text, int $start, int $end): string
    {
        if ($end <= $start) {
            return '';
        }

        if (self::isAscii($text)) {
            return substr($text, $start, $end - $start);
        }

        $chars = preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars) {
            return '';
        }

        return implode('', \array_slice($chars, $start, $end - $start));
    }

    /**
     * Every codepoint as its own string.
     *
     * @return list<string>
     */
    public static function chars(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        $chars = preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $chars ? [] : $chars;
    }
}
