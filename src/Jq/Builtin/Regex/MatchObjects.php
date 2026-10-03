<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use LTS\PhpXq\Json\JsonObject;

/**
 * Builds the jq values for a match: the `match` output object and the object of named captures.
 *
 * Key order follows jq 1.8: a match is offset, length, string, captures; a capture is offset, length,
 * string, name, except that an empty or unmatched group lists `string` before `length`. A group that did
 * not take part has offset -1 and a null string.
 *
 * @internal
 */
final class MatchObjects
{
    private function __construct()
    {
    }

    /**
     * @param array<array{0: ?string, 1: int}> $groups one PCRE match as returned by {@see RegexEngine::find()}
     * @param list<?string>                    $names
     */
    public static function match(array $groups, array $names, CodepointCursor $cursor): JsonObject
    {
        $whole    = (string)$groups[0][0];
        $captures = [];
        foreach ($names as $index => $name) {
            [$text, $byteOffset] = $groups[$index + 1] ?? [null, -1];
            if (null === $text) {
                $captures[] = new JsonObject(['offset' => -1, 'string' => null, 'length' => 0, 'name' => $name]);
            } elseif ('' === $text) {
                $captures[] = new JsonObject(['offset' => $cursor->offset($byteOffset), 'string' => '', 'length' => 0, 'name' => $name]);
            } else {
                $captures[] = new JsonObject([
                    'offset' => $cursor->offset($byteOffset),
                    'length' => CodepointCursor::length($text),
                    'string' => $text,
                    'name'   => $name,
                ]);
            }
        }

        return new JsonObject([
            'offset'   => $cursor->offset($groups[0][1]),
            'length'   => CodepointCursor::length($whole),
            'string'   => $whole,
            'captures' => $captures,
        ]);
    }

    /**
     * The object `capture` builds: every named group by name with its string (null when unmatched); a name
     * used twice keeps the later group.
     *
     * @param array<array{0: ?string, 1: int}> $groups
     * @param list<?string>                    $names
     */
    public static function named(array $groups, array $names): JsonObject
    {
        $members = [];
        foreach ($names as $index => $name) {
            if (null !== $name) {
                $members[$name] = ($groups[$index + 1] ?? [null])[0];
            }
        }

        return new JsonObject($members);
    }
}
