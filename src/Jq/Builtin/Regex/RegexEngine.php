<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * Runs an {@see OnigRegex} over a subject with jq 1.8's iteration rules: after a match the search resumes
 * at its end, and after an empty match one character further; a search that starts exactly at the end of
 * the subject still runs, so `"a" | gsub("$"; "b")` finds the empty match there.
 *
 * @internal
 */
final class RegexEngine
{
    private const int FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    private function __construct()
    {
    }

    /**
     * Whether the subject is free of non-ASCII bytes (byte and codepoint offsets then agree).
     */
    public static function isAscii(string $subject): bool
    {
        return 1 !== preg_match('/[\x80-\xFF]/', $subject);
    }

    /**
     * Every match (only the first when $all is false) as PCRE's offset-capture arrays: index 0 is the
     * whole match, then one entry per group by number (named groups also appear under their name, which
     * callers ignore); an unmatched group is [null, -1].
     *
     * @return list<array<array{0: ?string, 1: int}>>
     *
     * @throws JqException when PCRE fails at match time
     */
    public static function find(OnigRegex $regex, string $subject, bool $all, bool $ascii): array
    {
        $length  = \strlen($subject);
        $offset  = 0;
        $matches = [];

        do {
            $groups = self::search($regex, $subject, $offset, $ascii);
            if (null === $groups) {
                break;
            }

            $start  = $groups[0][1];
            $width  = \strlen((string)$groups[0][0]);
            $end    = $start + $width;
            $offset = $end;

            if (0 === $width) {
                if ($end >= $length) {
                    if (!$regex->ignoreEmpty) {
                        $matches[] = $groups;
                    }

                    break;
                }

                $offset += CodepointCursor::characterWidth($subject, $end);
                if ($regex->ignoreEmpty) {
                    continue;
                }
            }

            $matches[] = $groups;
            if (!$all) {
                break;
            }
        } while ($offset <= $length);

        return $matches;
    }

    /**
     * Whether the pattern matches anywhere (a match of width zero does not count under the `n` modifier).
     *
     * @throws JqException when PCRE fails at match time
     */
    public static function matches(OnigRegex $regex, string $subject, bool $ascii): bool
    {
        if ($regex->ignoreEmpty) {
            return [] !== self::find($regex, $subject, false, $ascii);
        }

        $found = preg_match($regex->pcre($ascii), $subject);
        if (false === $found) {
            throw new JqException(preg_last_error_msg());
        }

        return 1 === $found;
    }

    /**
     * One search from $offset: the first match, or with the `l` modifier the longest match over every start
     * position at or after $offset (the earliest one wins a tie).
     *
     * @return ?array<array{0: ?string, 1: int}>
     *
     * @throws JqException when PCRE fails at match time
     */
    private static function search(OnigRegex $regex, string $subject, int $offset, bool $ascii): ?array
    {
        if (!$regex->longest) {
            $found = preg_match($regex->pcre($ascii), $subject, $groups, self::FLAGS, $offset);
            if (false === $found) {
                throw new JqException(preg_last_error_msg());
            }

            return 1 === $found ? $groups : null;
        }

        $anchored  = $regex->anchoredPcre($ascii);
        $length    = \strlen($subject);
        $best      = null;
        $bestWidth = -1;
        for ($position = $offset; $position <= $length; $position += CodepointCursor::characterWidth($subject, $position)) {
            $found = preg_match($anchored, $subject, $groups, self::FLAGS, $position);
            if (false === $found) {
                throw new JqException(preg_last_error_msg());
            }

            if (1 === $found && \strlen((string)$groups[0][0]) > $bestWidth) {
                $best      = $groups;
                $bestWidth = \strlen((string)$groups[0][0]);
            }
        }

        return $best;
    }
}
