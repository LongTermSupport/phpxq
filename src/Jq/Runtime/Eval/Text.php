<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * Codepoint-indexed string helpers over UTF-8 without mbstring. Strings in the value model are always valid
 * UTF-8.
 *
 * Slicing keeps an index of the last text it sliced: its codepoint count and the byte offset of every
 * STRIDE-th codepoint. Slicing the same text again (`$s[$i:$i+1]` over every `$i`) then costs a jump to
 * the nearest checkpoint and a short scan, rather than a pass over the whole text.
 *
 * @internal
 */
final class Text
{
    /**
     * Codepoints between two checkpoints of the slice index.
     */
    private const int STRIDE = 128;

    /**
     * Matches up to STRIDE codepoints; the offset of each match is one checkpoint.
     */
    private const string CHECKPOINT_RUN ='/.{1,' . self::STRIDE . '}/su';

    private static string $indexed = '';

    private static int $indexedLength = 0;

    private static bool $indexedAscii = true;

    /**
     * @var list<int> byte offset of codepoint k * STRIDE of the indexed text, at position k
     */
    private static array $checkpoints = [];

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

        if ($text === self::$indexed) {
            return self::$indexedLength;
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

        self::index($text);
        if (self::$indexedAscii) {
            return substr($text, $start, $end - $start);
        }

        $from = self::byteOffset($text, $start);

        return substr($text, $from, self::byteOffset($text, $end) - $from);
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

    private static function index(string $text): void
    {
        if ($text === self::$indexed) {
            return;
        }

        self::$indexed      = $text;
        self::$indexedAscii = self::isAscii($text);
        self::$checkpoints  = [];
        if (self::$indexedAscii) {
            self::$indexedLength = \strlen($text);

            return;
        }

        self::$indexedLength = \strlen($text) - (int)preg_match_all('/[\x80-\xBF]/', $text);
        if (false !== preg_match_all(self::CHECKPOINT_RUN, $text, $runs, \PREG_OFFSET_CAPTURE)) {
            self::$checkpoints = array_column($runs[0], 1);
        }
    }

    /**
     * Byte offset of a codepoint of the indexed non-ASCII text, 0..length.
     */
    private static function byteOffset(string $text, int $codepoint): int
    {
        if ($codepoint >= self::$indexedLength) {
            return \strlen($text);
        }

        $byte = self::$checkpoints[intdiv($codepoint, self::STRIDE)];
        for ($left = $codepoint % self::STRIDE; $left > 0; --$left) {
            $byte += match (true) {
                \ord($text[$byte]) < 0x80 => 1,
                \ord($text[$byte]) < 0xE0 => 2,
                \ord($text[$byte]) < 0xF0 => 3,
                default                   => 4,
            };
        }

        return $byte;
    }
}
