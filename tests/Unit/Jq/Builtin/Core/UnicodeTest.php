<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\Unicode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UnicodeTest extends TestCase
{
    public function testLengthCountsCodepoints(): void
    {
        self::assertSame(0, Unicode::length(''));
        self::assertSame(3, Unicode::length('abc'));
        self::assertSame(4, Unicode::length("a\u{e9}\u{20ac}\u{1f600}"));
    }

    public function testCodepoints(): void
    {
        self::assertSame([], Unicode::codepoints(''));
        self::assertSame([97, 0xE9, 0x20AC, 0x1F600], Unicode::codepoints("a\u{e9}\u{20ac}\u{1f600}"));
    }

    #[DataProvider('encodings')]
    public function testEncode(int $codepoint, string $expected): void
    {
        self::assertSame($expected, Unicode::encode($codepoint));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function encodings(): iterable
    {
        yield 'ascii' => [65, 'A'];
        yield 'two bytes' => [0xE9, "\u{e9}"];
        yield 'three bytes' => [0x20AC, "\u{20ac}"];
        yield 'four bytes' => [0x1F600, "\u{1f600}"];
        yield 'highest codepoint' => [0x10FFFF, "\u{10ffff}"];
        yield 'surrogate' => [0xD800, "\u{fffd}"];
        yield 'too large' => [0x110000, "\u{fffd}"];
        yield 'negative' => [-5, "\u{fffd}"];
        yield 'NUL' => [0, "\u{0}"];
    }

    public function testCharacters(): void
    {
        self::assertSame([], Unicode::characters(''));
        self::assertSame(['a', "\u{e9}", "\u{1f600}"], Unicode::characters("a\u{e9}\u{1f600}"));
    }

    public function testTrim(): void
    {
        self::assertSame('a b', Unicode::trim("  a b\t", true, true));
        self::assertSame("a b\t", Unicode::trim("  a b\t", true, false));
        self::assertSame('  a b', Unicode::trim("  a b\t", false, true));
        self::assertSame('x', Unicode::trim("\u{3000}x\u{a0}", true, true));
        self::assertSame("\u{200b}", Unicode::trim("\u{200b}", true, true));
    }
}
