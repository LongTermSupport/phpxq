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
        $pcre    = $regex->pcre($ascii);
        $length  = \strlen($subject);
        $offset  = 0;
        $matches = [];

        do {
            $found = preg_match($pcre, $subject, $groups, self::FLAGS, $offset);
            if (false === $found) {
                throw new JqException(preg_last_error_msg());
            }

            if (0 === $found) {
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
}
