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
    public function testValidTextIsReturnedUnchanged(): void
    {
        self::assertSame("a\u{e9}\u{20ac}\u{1f600}", Utf8::sanitize("a\u{e9}\u{20ac}\u{1f600}"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'lone continuation'      => ["a\x80b", "a\u{fffd}b"];
        yield 'two lone continuations' => ["\x80\x80", "\u{fffd}\u{fffd}"];
        yield 'truncated at end'       => ["a\xe2\x82", "a\u{fffd}"];
        yield 'truncated then ascii'   => ["\xe2\x82A", "\u{fffd}A"];
        yield 'overlong two byte'      => ["\xc0\x80", "\u{fffd}\u{fffd}"];
        yield 'surrogate'              => ["\xed\xa0\x80", "\u{fffd}"];
        yield 'above max'              => ["\xf4\x90\x80\x80", "\u{fffd}"];
        yield 'invalid lead'           => ["\xff", "\u{fffd}"];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidSequencesAreReplaced(string $input, string $expected): void
    {
        self::assertSame($expected, Utf8::sanitize($input));
    }

    public function testEncodeCodepoints(): void
    {
        self::assertSame('A', Utf8::encode(0x41));
        self::assertSame("\u{e9}", Utf8::encode(0xE9));
        self::assertSame("\u{20ac}", Utf8::encode(0x20AC));
        self::assertSame("\u{1f600}", Utf8::encode(0x1F600));
    }

    public function testCodepointOfOneCharacter(): void
    {
        self::assertSame(0x41, Utf8::codepoint('A'));
        self::assertSame(0x20AC, Utf8::codepoint("\u{20ac}"));
        self::assertSame(0x1F600, Utf8::codepoint("\u{1f600}"));
        self::assertSame(0xE9, Utf8::codepoint("\u{e9}"));
    }
}
