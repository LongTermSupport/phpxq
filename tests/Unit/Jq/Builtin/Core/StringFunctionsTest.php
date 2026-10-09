<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StringFunctionsTest extends TestCase
{
    public function testStartsWithAndEndsWith(): void
    {
        self::assertSame([false, true, false, true, false], array_map(
            static fn (string $text): mixed => Harness::call('startswith', $text, ['foo']),
            ['fo', 'foo', 'barfoo', 'foobar', 'barfoob'],
        ));
        self::assertSame([false, true, true, false, false], array_map(
            static fn (string $text): mixed => Harness::call('endswith', $text, ['foo']),
            ['fo', 'foo', 'barfoo', 'foobar', 'barfoob'],
        ));
        self::assertSame('startswith() requires string inputs', Harness::error('startswith', 1, ['a']));
        self::assertSame('startswith() requires string inputs', Harness::error('startswith', 'a', [1]));
        self::assertSame('endswith() requires string inputs', Harness::error('endswith', 1, ['a']));
    }

    #[DataProvider('trimStrings')]
    public function testTrimStr(string $name, string $input, string $argument, string $expected): void
    {
        self::assertSame($expected, Harness::call($name, $input, [$argument]));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function trimStrings(): iterable
    {
        yield 'ltrimstr removes a prefix' => ['ltrimstr', 'foobar', 'foo', 'bar'];
        yield 'ltrimstr whole string' => ['ltrimstr', 'foo', 'foo', ''];
        yield 'ltrimstr no match' => ['ltrimstr', 'afoo', 'foo', 'afoo'];
        yield 'ltrimstr empty prefix' => ['ltrimstr', 'xx', '', 'xx'];
        yield 'rtrimstr removes a suffix' => ['rtrimstr', 'barfoo', 'foo', 'bar'];
        yield 'rtrimstr whole string' => ['rtrimstr', 'foo', 'foo', ''];
        yield 'rtrimstr no match' => ['rtrimstr', 'foob', 'foo', 'foob'];
        yield 'rtrimstr empty suffix' => ['rtrimstr', 'xx', '', 'xx'];
    }

    public function testTrimStrRejectsNonStrings(): void
    {
        self::assertSame('startswith() requires string inputs', Harness::error('ltrimstr', 'hi', [1]));
        self::assertSame('startswith() requires string inputs', Harness::error('ltrimstr', 1, ['hi']));
        self::assertSame('endswith() requires string inputs', Harness::error('rtrimstr', 1, [1]));
    }

    public function testTrim(): void
    {
        self::assertSame('abc', Harness::call('trim', "  abc\t\n"));
        self::assertSame("abc  \n", Harness::call('ltrim', "  abc  \n"));
        self::assertSame('  abc', Harness::call('rtrim', "  abc  \n"));
        self::assertSame('', Harness::call('trim', " \n\t\r\f\u{b}"));
    }

    public function testTrimUnicodeWhitespace(): void
    {
        $space = "\u{9}\u{a}\u{b}\u{c}\u{d}\u{20}\u{85}\u{a0}\u{1680}\u{2000}\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}";

        self::assertSame('abc', Harness::call('trim', $space . 'abc' . $space));
        self::assertSame('abc' . $space, Harness::call('ltrim', $space . 'abc' . $space));
        self::assertSame($space . 'abc', Harness::call('rtrim', $space . 'abc' . $space));
        self::assertSame("\u{200b}x", Harness::call('ltrim', "\u{200b}x"));
    }

    public function testTrimRequiresAString(): void
    {
        self::assertSame('trim input must be a string', Harness::error('trim', 123));
        self::assertSame('trim input must be a string', Harness::error('ltrim', 123));
        self::assertSame('trim input must be a string', Harness::error('rtrim', 123));
    }

    public function testCaseConversion(): void
    {
        self::assertSame('USEFUL BUT NOT FOR é', Harness::call('ascii_upcase', 'useful but not for é'));
        self::assertSame('abc É', Harness::call('ascii_downcase', 'ABC É'));
        self::assertSame('ascii_downcase input must be a string', Harness::error('ascii_downcase', 1));
        self::assertSame('ascii_upcase input must be a string', Harness::error('ascii_upcase', null));
    }

    public function testExplode(): void
    {
        self::assertSame([102, 111, 111], Harness::call('explode', 'foo'));
        self::assertSame([0x3BC, 0x1F600, 0x20AC], Harness::call('explode', "\u{3bc}\u{1f600}\u{20ac}"));
        self::assertSame([], Harness::call('explode', ''));
        self::assertSame('explode input must be a string', Harness::error('explode', 1));
    }

    public function testImplode(): void
    {
        self::assertSame('ABC', Harness::call('implode', [65, 66, 67]));
        self::assertSame("\u{3bc}\u{1f600}", Harness::call('implode', [0x3BC, 0x1F600]));
        self::assertSame('', Harness::call('implode', []));
    }

    public function testImplodeReplacesInvalidCodepoints(): void
    {
        $replacement = "\u{fffd}";

        self::assertSame(
            $replacement . "\u{0}\u{1}\u{2}\u{3}\u{10ffff}" . $replacement . "\u{d7ff}" . $replacement . $replacement . "\u{e000}\u{1}\u{1}",
            Harness::call('implode', [-1, 0, 1, 2, 3, 1114111, 1114112, 55295, 55296, 57343, 57344, 1.1, 1.9]),
        );
    }

    public function testImplodeValidation(): void
    {
        self::assertSame('implode input must be an array', Harness::error('implode', 123));
        self::assertSame('string ("a") can\'t be imploded, unicode codepoint needs to be numeric', Harness::error('implode', ['a']));
        self::assertSame("number (null) can't be imploded, unicode codepoint needs to be numeric", Harness::error('implode', [\NAN]));
    }

    public function testSplit(): void
    {
        self::assertSame(['a,b', 'c', 'd', 'e,f'], Harness::call('split', 'a,b, c, d, e,f', [', ']));
        self::assertSame(['', 'a,b', 'c', 'd', 'e,f', ''], Harness::call('split', ', a,b, c, d, e,f, ', [', ']));
        self::assertSame(['a', 'b', 'c'], Harness::call('split', 'abc', ['']));
        self::assertSame(["\u{3bc}", 'x'], Harness::call('split', "\u{3bc}x", ['']));
        self::assertSame([], Harness::call('split', '', [',']));
        self::assertSame([], Harness::call('split', '', ['']));
        self::assertSame(['abc'], Harness::call('split', 'abc', [',']));
        self::assertSame('split input and separator must be strings', Harness::error('split', 1, [',']));
        self::assertSame('split input and separator must be strings', Harness::error('split', 'a', [1]));
    }

    public function testJoin(): void
    {
        self::assertSame('a, b,c,d, e', Harness::call('join', ['a', 'b,c,d', 'e'], [', ']));
        self::assertSame('a 1 2.3 true  false', Harness::call('join', Harness::json('["a",1,2.3,true,null,false]'), [' ']));
        self::assertSame('', Harness::call('join', [], [',']));
        self::assertSame('', Harness::call('join', [''], [',']));
        self::assertSame(',,', Harness::call('join', [null, null, null], [',']));
        self::assertSame('a,', Harness::call('join', ['a', null], [',']));
        self::assertSame('x-y', Harness::call('join', Harness::json('{"a":"x","b":"y"}'), ['-']));
        self::assertSame('Cannot iterate over null (null)', Harness::error('join', null, [',']));
    }

    public function testJoinCannotAddNestedValues(): void
    {
        self::assertSame(
            'string ("1,2,") and object ({"a":{"b":{"c":33}}}) cannot be added',
            Harness::error('join', Harness::json('["1","2",{"a":{"b":{"c":33}}}]'), [',']),
        );
        self::assertSame(
            'string ("1,2,") and array ([3,4,5]) cannot be added',
            Harness::error('join', Harness::json('["1","2",[3,4,5]]'), [',']),
        );
    }

    public function testStringIndices(): void
    {
        self::assertSame([1, 4, 8, 13], Harness::call('_strindices', 'a,bc,def,ghij,klmno', [',']));
        self::assertSame([1, 3, 5, 7], Harness::call('_strindices', 'xababababax', ['aba']));
        self::assertSame([2, 3], Harness::call('_strindices', "\u{1f1ec}\u{1f1e7}oo", ['o']));
        self::assertSame([1, 2], Harness::call('_strindices', "\u{192}oo", ['o']));
        self::assertSame([14], Harness::call('_strindices', "\u{437}\u{434}\u{440}\u{430}\u{432}\u{441}\u{442}\u{432}\u{443}\u{439} \u{43c}\u{438}\u{440}!", ['!']));
        self::assertSame([], Harness::call('_strindices', 'abc', ['']));
        self::assertSame([], Harness::call('_strindices', 'abc', ['x']));
    }

    public function testStringIndicesCountCodepointsBetweenSeveralMultibyteHits(): void
    {
        self::assertSame([1, 3, 5], Harness::call('_strindices', "\u{1f600}a\u{e9}a\u{1f600}a", ['a']));
        self::assertSame([1, 3, 4], Harness::call('_strindices', "a\u{e9}b\u{e9}\u{e9}", ["\u{e9}"]));
        self::assertSame([0, 1, 2], Harness::call('_strindices', "\u{e9}\u{e9}\u{e9}\u{e9}", ["\u{e9}\u{e9}"]));
        self::assertSame([0, 4], Harness::call('_strindices', "\u{4e2d}x\u{e9}\u{1f600}\u{4e2d}", ["\u{4e2d}"]));
    }

    public function testStringIndicesValidation(): void
    {
        self::assertSame('number (123) cannot be searched, as it is not a string', Harness::error('_strindices', 123, ['abc']));
        self::assertSame('number (123) is not a string', Harness::error('_strindices', 'abc', [123]));
    }

    public function testArrayIndices(): void
    {
        self::assertSame([1, 2, 6], Harness::call('_array_indices', [0, 1, 1, 2, 3, 4, 1, 5], [[1]]));
        self::assertSame([1, 8], Harness::call('_array_indices', [0, 1, 2, 3, 1, 4, 2, 5, 1, 2, 6, 7], [[1, 2]]));
        self::assertSame([], Harness::call('_array_indices', [1], [[1, 2]]));
        self::assertSame([], Harness::call('_array_indices', [1, 2], [[]]));
        self::assertSame([0, 1], Harness::call('_array_indices', [1, 1], [[1]]));
        self::assertSame(
            'number (1) and number (2) cannot be searched, as they are not both arrays',
            Harness::error('_array_indices', 1, [2]),
        );
    }
}
