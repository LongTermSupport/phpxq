<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression\Parser;

use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\Parser\StringLiteral;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StringLiteral::class)]
final class StringLiteralTest extends TestCase
{
    #[DataProvider('decodeProvider')]
    public function testDecode(string $source, string $expected): void
    {
        self::assertSame($expected, StringLiteral::decode($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function decodeProvider(): iterable
    {
        yield 'plain'            => ['abc', 'abc'];
        yield 'newline'          => ['a\nb', "a\nb"];
        yield 'tab and return'   => ['\t\r', "\t\r"];
        yield 'quote'            => ['\"', '"'];
        yield 'backslash'        => ['\\\\', '\\'];
        yield 'slash'            => ['\/', '/'];
        yield 'bell and others'  => ['\a\b\f\v\0', "\x07\x08\x0c\x0b\x00"];
        yield 'hex'              => ['\x41', 'A'];
        yield 'unicode'         => ['é', "\u{e9}"];
        yield 'surrogate pair'   => ['\ud83d' . '\ude00', "\u{1F600}"];
        yield 'long unicode'     => ['\U0001F600', "\u{1F600}"];
        yield 'unknown escape'   => ['\q', '\q'];
        yield 'trailing slash'   => ['a\\', 'a\\'];
        yield 'bad hex stays'    => ['\xZZ', '\xZZ'];
        yield 'lone surrogate'   => ['\ud83d', "\u{FFFD}"];
    }

    public function testScanDoubleFindsClosingQuote(): void
    {
        self::assertSame(4, StringLiteral::scanDouble('"abc"', 1));
        self::assertSame(5, StringLiteral::scanDouble('"a\"b"', 1));
    }

    public function testScanDoubleSkipsInterpolationQuotes(): void
    {
        $source = '"x \(.a | "q)") y" rest';

        self::assertSame(17, StringLiteral::scanDouble($source, 1));
    }

    public function testScanDoubleUnterminated(): void
    {
        $this->expectException(ExpressionSyntaxException::class);
        StringLiteral::scanDouble('"abc', 1);
    }

    public function testSkipInterpolationUnterminated(): void
    {
        $this->expectException(ExpressionSyntaxException::class);
        StringLiteral::skipInterpolation('.a + (1', 0);
    }

    public function testSkipInterpolationNestedParensAndSingleQuotes(): void
    {
        self::assertSame(8, StringLiteral::skipInterpolation("(a) ')') tail", 0));
    }

    public function testSplitWithoutInterpolation(): void
    {
        self::assertSame(["a\nb"], StringLiteral::split('a\nb'));
    }

    public function testSplitWithInterpolation(): void
    {
        self::assertSame(
            ['I like ', ['.value', 9], ' and ', ['.another', 23]],
            StringLiteral::split('I like \(.value) and \(.another)'),
        );
    }

    public function testSplitEscapedBackslashBeforeParen(): void
    {
        self::assertSame(['\('], StringLiteral::split('\\\('));
    }

    public function testSplitDropsEmptyLiterals(): void
    {
        self::assertSame([['.a', 2]], StringLiteral::split('\(.a)'));
    }
}
