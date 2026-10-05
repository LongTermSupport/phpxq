<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Emitter;

use LTS\PhpXq\Yaml\Emitter\ScalarAnalysis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ScalarAnalysisTest extends TestCase
{
    #[DataProvider('cases')]
    public function testFlags(string $value, bool $flowPlain, bool $blockPlain, bool $single, bool $block, bool $multiline): void
    {
        $analysis = ScalarAnalysis::of($value);

        self::assertSame($flowPlain, $analysis->flowPlainAllowed, 'flowPlain');
        self::assertSame($blockPlain, $analysis->blockPlainAllowed, 'blockPlain');
        self::assertSame($single, $analysis->singleQuotedAllowed, 'single');
        self::assertSame($block, $analysis->blockAllowed, 'block');
        self::assertSame($multiline, $analysis->multiline, 'multiline');
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool, bool, bool}>
     */
    public static function cases(): iterable
    {
        // value, flowPlain, blockPlain, singleQuoted, blockAllowed, multiline
        yield 'empty'                 => ['', false, true, true, false, false];

        yield 'word'                  => ['abc', true, true, true, true, false];

        yield 'words with space'      => ['a b', true, true, true, true, false];

        yield 'colon then space'      => ['a: b', false, false, true, true, false];

        yield 'colon inside'          => ['a:b', false, true, true, true, false];

        yield 'comma'                 => ['a,b', false, true, true, true, false];

        yield 'hash after space'      => ['a #b', false, false, true, true, false];

        yield 'hash inside word'      => ['a#b', true, true, true, true, false];

        yield 'leading dash space'    => ['- a', false, false, true, true, false];

        yield 'leading dash word'     => ['-a', true, true, true, true, false];

        yield 'leading question'      => ['?a', false, true, true, true, false];

        yield 'leading question sp'   => ['? a', false, false, true, true, false];

        yield 'document marker'       => ['---', false, false, true, true, false];

        yield 'leading space'         => [' a', false, false, true, true, false];

        yield 'trailing space'        => ['a ', false, false, true, false, false];

        yield 'newline'               => ["a\nb", false, false, true, true, true];

        yield 'space before newline'  => ["a \nb", false, false, false, false, true];

        yield 'newline before space'  => ["a\n b", false, false, false, true, true];

        yield 'tab is special'        => ["a\tb", false, false, false, false, false];

        yield 'astral is special'     => ["a\u{1F600}", false, false, false, false, false];

        yield 'bmp is fine'           => ["caf\u{e9}", true, true, true, true, false];

        yield 'flow brackets'         => ['a[b', false, true, true, true, false];
    }

    /**
     * A value holding one non-ASCII character is special (needs double quotes) unless the character is
     * printable, and a multiline one when the character is a line break.
     */
    #[DataProvider('utf8Provider')]
    public function testUtf8CharacterClassification(string $character, bool $special, bool $lineBreak): void
    {
        $analysis = ScalarAnalysis::of('a' . $character . 'b');

        self::assertSame($lineBreak, $analysis->multiline, 'multiline');
        self::assertSame(!$special && !$lineBreak, $analysis->flowPlainAllowed, 'flowPlain');
        self::assertSame(!$special && !$lineBreak, $analysis->blockPlainAllowed, 'blockPlain');
        self::assertSame(!$special, $analysis->singleQuotedAllowed, 'single');
        self::assertSame(!$special, $analysis->blockAllowed, 'block');
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function utf8Provider(): iterable
    {
        yield 'U+0080 C1 control' => ["\xC2\x80", true, false];

        yield 'U+0085 next line' => ["\xC2\x85", true, true];

        yield 'U+009F C1 control' => ["\xC2\x9F", true, false];

        yield 'U+00A0 no-break space' => ["\xC2\xA0", false, false];

        yield 'U+00E9' => ["\xC3\xA9", false, false];

        yield 'U+07FF last two byte' => ["\xDF\xBF", false, false];

        yield 'U+0800 first three byte' => ["\xE0\xA0\x80", false, false];

        yield 'U+2027' => ["\xE2\x80\xA7", false, false];

        yield 'U+2028 line separator' => ["\xE2\x80\xA8", false, true];

        yield 'U+2029 paragraph separator' => ["\xE2\x80\xA9", false, true];

        yield 'U+202A' => ["\xE2\x80\xAA", false, false];

        yield 'U+2068' => ["\xE2\x81\xA8", false, false];

        yield 'U+3028' => ["\xE3\x80\xA8", false, false];

        yield 'U+D7FF last before surrogates' => ["\xED\x9F\xBF", false, false];

        yield 'U+D800 surrogate' => ["\xED\xA0\x80", true, false];

        yield 'U+E000 private use' => ["\xEE\x80\x80", false, false];

        yield 'U+F8FF private use' => ["\xEF\xA3\xBF", false, false];

        yield 'U+FEFE' => ["\xEF\xBB\xBE", false, false];

        yield 'U+FEFF byte order mark' => ["\xEF\xBB\xBF", true, false];

        yield 'U+FF00' => ["\xEF\xBC\x80", false, false];

        yield 'U+FFC0' => ["\xEF\xBF\x80", false, false];

        yield 'U+FFBF' => ["\xEF\xBE\xBF", false, false];

        yield 'U+FFFD replacement character' => ["\xEF\xBF\xBD", false, false];

        yield 'U+FFFE noncharacter' => ["\xEF\xBF\xBE", true, false];

        yield 'U+FFFF noncharacter' => ["\xEF\xBF\xBF", true, false];

        yield 'U+10000 astral' => ["\xF0\x90\x80\x80", true, false];
    }

    public function testMultiByteCharactersDoNotHideTheCharacterThatFollows(): void
    {
        $analysis = ScalarAnalysis::of("\u{e9}\t");

        self::assertFalse($analysis->singleQuotedAllowed);
        self::assertFalse(ScalarAnalysis::of("\u{800}\t")->singleQuotedAllowed);
        self::assertFalse(ScalarAnalysis::of("\u{e9}\u{800}\t")->singleQuotedAllowed);
    }
}
