<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\Codec\Utf8;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class Utf8Test extends TestCase
{
    private const string R = "\u{fffd}";

    public function testValidTextIsReturnedUnchanged(): void
    {
        self::assertSame("a\u{e9}\u{20ac}\u{1f600}", Utf8::sanitize("a\u{e9}\u{20ac}\u{1f600}"));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidSequencesAreReplaced(string $input, string $expected): void
    {
        self::assertSame($expected, Utf8::sanitize($input));
        self::assertSame('x' . $expected . 'y', Utf8::sanitize('x' . $input . 'y'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        $r = self::R;

        yield 'lone continuation'         => ["a\x80b", 'a' . $r . 'b'];
        yield 'two lone continuations'    => ["\x80\x80", $r . $r];
        yield 'truncated then ascii'      => ["\xe2\x82A", $r . 'A'];
        yield 'overlong two byte'         => ["\xc0\x80", $r . $r];
        yield 'C1 lead'                   => ["\xc1\xbf", $r . $r];
        yield 'surrogate'                 => ["\xed\xa0\x80", $r];
        yield 'surrogate DFFF'            => ["\xed\xbf\xbf", $r];
        yield 'above max'                 => ["\xf4\x90\x80\x80", $r];
        yield 'above max F7'              => ["\xf7\xbf\xbf\xbf", $r . $r . $r . $r];
        yield 'invalid lead'              => ["\xff", $r];
        yield 'F5 lead'                   => ["\xf5\x80\x80\x80", $r . $r . $r . $r];
        yield 'F8 lead'                   => ["\xf8", $r];
        yield 'overlong three byte'       => ["\xe0\x9f\xbf", $r];
        yield 'overlong four byte'        => ["\xf0\x8f\xbf\xbf", $r];
        yield 'overlong E0 80 80'         => ["\xe0\x80\x80", $r];
        yield 'two byte bad continuation' => ["\xc3\x28", $r . '('];
        yield 'three byte bad second'     => ["\xe2\x28\xa1", $r . '(' . $r];
        yield 'three byte bad third'      => ["\xe2\x82\x28", $r . '('];
        yield 'four byte bad second'      => ["\xf0\x28\x8c\xbc", $r . '(' . $r . $r];
        yield 'four byte bad third'       => ["\xf0\x90\x28\xbc", $r . '(' . $r];
        yield 'four byte bad fourth'      => ["\xf0\x90\x80\x28", $r . '('];
        yield 'three byte two bad'        => ["\xe2\x28\x28", $r . '(('];
        yield 'four byte three bad'       => ["\xf0\x28\x28\x28", $r . '((('];
        yield 'four byte two bad'         => ["\xf0\x90\x28\x28", $r . '(('];
        yield 'continuation 0xC0 mask'    => ["\xe2\xc0\x80", $r . $r . $r];
        yield 'continuation 0xC1'         => ["\xe2\x82\xc1", $r . $r];
    }

    #[DataProvider('truncatedProvider')]
    public function testTruncatedSequencesAtEnd(string $input): void
    {
        self::assertSame('a' . self::R, Utf8::sanitize('a' . $input));
        self::assertSame(self::R, Utf8::sanitize($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truncatedProvider(): iterable
    {
        yield 'two byte lead'        => ["\xc3"];
        yield 'three byte lead'      => ["\xe2"];
        yield 'three byte two'       => ["\xe2\x82"];
        yield 'four byte lead'       => ["\xf0"];
        yield 'four byte two'        => ["\xf0\x9f"];
        yield 'four byte three'      => ["\xf0\x9f\x98"];
    }

    /**
     * Valid characters beside an invalid byte must survive the slow path unchanged.
     */
    #[DataProvider('characterProvider')]
    public function testValidCharactersSurviveBesideInvalidByte(string $character, int $codepoint): void
    {
        self::assertGreaterThan(0, $codepoint);
        self::assertSame($character . self::R . $character, Utf8::sanitize($character . "\xff" . $character));
        self::assertSame(self::R . $character, Utf8::sanitize("\xff" . $character));
        self::assertSame($character . self::R, Utf8::sanitize($character . "\xff"));
    }

    #[DataProvider('characterProvider')]
    public function testEncodeProducesTheBytes(string $character, int $codepoint): void
    {
        self::assertSame($character, Utf8::encode($codepoint));
    }

    #[DataProvider('characterProvider')]
    public function testCodepointDecodesTheBytes(string $character, int $codepoint): void
    {
        self::assertSame($codepoint, Utf8::codepoint($character));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function characterProvider(): iterable
    {
        yield 'U+007F'   => ["\x7f", 0x7F];
        yield 'U+0080'   => ["\xc2\x80", 0x80];
        yield 'U+00FF'   => ["\xc3\xbf", 0xFF];
        yield 'U+07FF'   => ["\xdf\xbf", 0x7FF];
        yield 'U+0800'   => ["\xe0\xa0\x80", 0x800];
        yield 'U+0FFF'   => ["\xe0\xbf\xbf", 0xFFF];
        yield 'U+20AC'   => ["\xe2\x82\xac", 0x20AC];
        yield 'U+D7FF'   => ["\xed\x9f\xbf", 0xD7FF];
        yield 'U+E000'   => ["\xee\x80\x80", 0xE000];
        yield 'U+FFFF'   => ["\xef\xbf\xbf", 0xFFFF];
        yield 'U+10000'  => ["\xf0\x90\x80\x80", 0x10000];
        yield 'U+12345'  => ["\xf0\x92\x8d\x85", 0x12345];
        yield 'U+3FFFF'  => ["\xf0\xbf\xbf\xbf", 0x3FFFF];
        yield 'U+40000'  => ["\xf1\x80\x80\x80", 0x40000];
        yield 'U+1F600'  => ["\xf0\x9f\x98\x80", 0x1F600];
        yield 'U+10FFFF' => ["\xf4\x8f\xbf\xbf", 0x10FFFF];
    }

    public function testEncodeInvalidCodepoints(): void
    {
        self::assertSame("\x00", Utf8::encode(0));
        self::assertSame("\x00", Utf8::encode(-5));
        self::assertSame(self::R, Utf8::encode(0xD800));
        self::assertSame(self::R, Utf8::encode(0xDFFF));
        self::assertSame(self::R, Utf8::encode(0x110000));
    }
}
