<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\LongestMatches;
use LTS\PhpXq\Jq\Builtin\Regex\OnigRegex;
use LTS\PhpXq\Jq\Builtin\Regex\RegexEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The `l` modifier's longest match at or after an offset, from one pass over the start positions per
 * subject: each later search is a lookup, so `[match("a"; "gl")]` is linear rather than quadratic.
 *
 * @internal
 */
#[CoversClass(LongestMatches::class)]
#[CoversClass(RegexEngine::class)]
#[Small]
final class LongestMatchesTest extends TestCase
{
    private const int LONG = 50000;

    /**
     * @param list<array{string, int}> $expected
     */
    #[DataProvider('searches')]
    public function testGlobalLongestMatches(string $pattern, string $subject, bool $ascii, array $expected): void
    {
        $found = array_map(
            static fn (array $groups): array => $groups[0],
            RegexEngine::find(OnigRegex::compile($pattern, 'gl'), $subject, true, $ascii),
        );

        self::assertSame($expected, $found);
    }

    /**
     * @return iterable<string, array{string, string, bool, list<array{string, int}>}>
     */
    public static function searches(): iterable
    {
        yield 'every character'            => ['a', 'aaa', true, [['a', 0], ['a', 1], ['a', 2]]];
        yield 'a longer match later'       => ['b|cc', 'bcc b', true, [['cc', 1], ['b', 4]]];
        yield 'the earlier wins a tie'     => ['\d+|ab', 'ab12 cd34', true, [['ab', 0], ['12', 2], ['34', 7]]];
        yield 'no match at all'            => ['x', 'abc', true, []];
        yield 'an empty match at the end'  => ['$', 'ab', true, [['', 2]]];
        yield 'multibyte start positions'  => ['\p{L}+', "\u{e9} ab \u{e9}\u{e9}\u{e9}", false, [["\u{e9}\u{e9}\u{e9}", 6]]];
    }

    public function testGroupsOfTheChosenMatchAreReturned(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('(a)(b)?', 'l'), 'a ab', false, true);

        self::assertSame([['ab', 2], ['a', 2], ['b', 3]], $matches[0]);
    }

    public function testEveryMatchOfALongSubjectInLinearTime(): void
    {
        $matches = RegexEngine::find(OnigRegex::compile('a', 'gl'), str_repeat('a', self::LONG), true, true);

        self::assertCount(self::LONG, $matches);
        self::assertSame(['a', self::LONG - 1], $matches[self::LONG - 1][0]);
    }

    public function testASearchPastTheLastMatchFindsNothing(): void
    {
        $longest = new LongestMatches(OnigRegex::compile('a', 'l'), 'ab', true);

        self::assertSame([['a', 0]], $longest->from(0));
        self::assertNull($longest->from(1));
    }
}
