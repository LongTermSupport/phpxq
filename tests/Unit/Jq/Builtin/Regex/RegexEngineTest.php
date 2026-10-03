<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\OnigRegex;
use LTS\PhpXq\Jq\Builtin\Regex\RegexEngine;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RegexEngineTest extends TestCase
{
    public function testIsAscii(): void
    {
        self::assertTrue(RegexEngine::isAscii('plain text'));
        self::assertTrue(RegexEngine::isAscii(''));
        self::assertFalse(RegexEngine::isAscii('caf' . "\u{e9}"));
    }

    public function testFindReturnsOnlyTheFirstMatchWithoutGlobal(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('a', null), 'banana', false, true);

        self::assertCount(1, $matches);
        self::assertSame(['a', 1], $matches[0][0]);
    }

    public function testFindReturnsEveryMatchWithGlobal(): void
    {
        $offsets = array_map(
            static fn (array $groups): int => $groups[0][1],
            RegexEngine::find(OnigRegex::compile('a', 'g'), 'banana', true, true),
        );

        self::assertSame([1, 3, 5], $offsets);
    }

    public function testEmptyMatchAdvancesOneCharacterNotOneByte(): void
    {
        $offsets = array_map(
            static fn (array $groups): int => $groups[0][1],
            RegexEngine::find(OnigRegex::compile('', 'g'), "\u{e9}\u{20ac}", true, false),
        );

        self::assertSame([0, 2, 5], $offsets);
    }

    public function testSearchAtTheEndOfTheSubjectStillRuns(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('$', 'g'), 'a', true, true);

        self::assertCount(1, $matches);
        self::assertSame(1, $matches[0][0][1]);
    }

    public function testEmptyMatchAfterANonEmptyOneAtTheEnd(): void
    {
        $widths = array_map(
            static fn (array $groups): int => \strlen((string)$groups[0][0]),
            RegexEngine::find(OnigRegex::compile('a*', 'g'), 'baa', true, true),
        );

        self::assertSame([0, 2, 0], $widths);
    }

    public function testIgnoreEmptySkipsEmptyMatches(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('a*', 'gn'), 'baab', true, true);

        self::assertCount(1, $matches);
        self::assertSame(['aa', 1], $matches[0][0]);
    }

    public function testUnmatchedGroupsAreReportedWithNegativeOffsets(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('(a)|(b)', null), 'b', false, true);

        self::assertSame([null, -1], $matches[0][1]);
        self::assertSame(['b', 0], $matches[0][2]);
    }

    public function testTrailingUnmatchedGroupIsPresent(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('(a)(b)?', null), 'a', false, true);

        self::assertArrayHasKey(2, $matches[0]);
        self::assertSame([null, -1], $matches[0][2]);
    }

    public function testLongestModifierPicksTheLongestMatchOverAllStartPositions(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('\D+', 'l'), 'ab12 cd34', false, true);

        self::assertCount(1, $matches);
        self::assertSame([' cd', 4], $matches[0][0]);
    }

    public function testLongestModifierPrefersTheEarlierMatchOnATie(): void
    {
        $strings = array_map(
            static fn (array $groups): string => (string)$groups[0][0],
            RegexEngine::find(OnigRegex::compile('\d+|ab', 'gl'), 'ab12 cd34', true, true),
        );

        self::assertSame(['ab', '12', '34'], $strings);
    }

    public function testLongestModifierOnNonAsciiSubjectsAdvancesByCharacter(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('\p{L}+', 'l'), "\u{e9} abc", false, false);

        self::assertSame(['abc', 3], $matches[0][0]);
    }

    public function testLongestModifierWithoutAnyMatch(): void
    {
        self::assertSame([], RegexEngine::find(OnigRegex::compile('x', 'l'), 'abc', false, true));
    }

    public function testMatches(): void
    {
        self::assertTrue(RegexEngine::matches(OnigRegex::compile('b', null), 'abc', true));
        self::assertFalse(RegexEngine::matches(OnigRegex::compile('x', null), 'abc', true));
        self::assertFalse(RegexEngine::matches(OnigRegex::compile('x*', 'n'), 'abc', true));
        self::assertTrue(RegexEngine::matches(OnigRegex::compile('b*', 'n'), 'abc', true));
    }

    public function testUnicodeWordBoundariesOnNonAsciiSubjects(): void
    {
        $subject = "a\u{0304} b";
        $matches = RegexEngine::find(OnigRegex::compile('\w+', null), $subject, false, false);

        self::assertSame("a\u{0304}", $matches[0][0][0]);
    }
}
