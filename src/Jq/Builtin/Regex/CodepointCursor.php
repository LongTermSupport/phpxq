<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

/**
 * Converts PCRE's byte offsets in a UTF-8 subject to jq's codepoint offsets. Offsets of one match are
 * close together, so the cursor remembers the last conversion and counts only the bytes in between.
 *
 * @internal
 */
final class CodepointCursor
{
    private int $lastByte = 0;

    private int $lastCodepoint = 0;

    public function __construct(
        private readonly string $subject,
        private readonly bool $ascii,
    ) {
    }

    /**
     * The number of codepoints in a piece of the subject (its byte length when the subject is ASCII).
     */
    public function measure(string $text): int
    {
        return $this->ascii ? \strlen($text) : self::length($text);
    }

    /**
     * The number of codepoints in a UTF-8 string.
     */
    public static function length(string $text): int
    {
        return \strlen($text) - self::continuationBytes($text);
    }

    public function offset(int $byteOffset): int
    {
        if ($this->ascii || $byteOffset === $this->lastByte) {
            return $this->ascii ? $byteOffset : $this->lastCodepoint;
        }

        if ($byteOffset > $this->lastByte) {
            $codepoint = $this->lastCodepoint + self::length(substr($this->subject, $this->lastByte, $byteOffset - $this->lastByte));
        } else {
            $codepoint = $this->lastCodepoint - self::length(substr($this->subject, $byteOffset, $this->lastByte - $byteOffset));
        }

        $this->lastByte      = $byteOffset;
        $this->lastCodepoint = $codepoint;

        return $codepoint;
    }

    /**
     * The width in bytes of the UTF-8 character starting at $byteOffset (1 at the end of the subject).
     */
    public static function characterWidth(string $subject, int $byteOffset): int
    {
        $lead = \ord(($subject[$byteOffset] ?? "\0")[0]);

        return match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default       => 1,
        };
    }

    private static function continuationBytes(string $text): int
    {
        $count = preg_match_all('/[\x80-\xBF]/', $text);

        return false === $count ? 0 : $count;
    }
}
