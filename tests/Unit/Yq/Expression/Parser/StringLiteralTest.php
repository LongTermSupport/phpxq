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
    private const string REPLACEMENT = "\xEF\xBF\xBD";

    private const string NO_CLOSING_PAREN = 'Bad expression, could not find matching `)`';

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
        yield 'surrogate pair'   => ['\ud83d\ude00', "\u{1F600}"];
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numericEscapeProvider(): iterable
    {
        yield 'ascii via u'             => ['\u0041', 'A'];
        yield 'two bytes'               => ['\u00e9', "\xC3\xA9"];
        yield 'two bytes lower bound'   => ['\u0080', "\xC2\x80"];
        yield 'one byte upper bound'    => ['\u007f', "\x7F"];
        yield 'two bytes upper bound'   => ['\u07ff', "\xDF\xBF"];
        yield 'three bytes lower bound' => ['\u0800', "\xE0\xA0\x80"];
        yield 'below surrogates'        => ['\ud7ff', "\xED\x9F\xBF"];
        yield 'above surrogates'        => ['\ue000', "\xEE\x80\x80"];
        yield 'three bytes upper bound' => ['\uffff', "\xEF\xBF\xBF"];
        yield 'four bytes lower bound'  => ['\U00010000', "\xF0\x90\x80\x80"];
        yield 'four bytes upper bound'  => ['\U0010FFFF', "\xF4\x8F\xBF\xBF"];
        yield 'beyond unicode'          => ['\U00110000', self::REPLACEMENT];
        yield 'pair lower bound'        => ['\ud800\udc00', "\xF0\x90\x80\x80"];
        yield 'pair upper bound'        => ['\udbff\udfff', "\xF4\x8F\xBF\xBF"];
        yield 'pair uppercase hex'      => ['\uD83D\uDE03', "\xF0\x9F\x98\x83"];
        yield 'pair then text'          => ['\ud83d\ude00X', "\xF0\x9F\x98\x80X"];
        yield 'two pairs'               => ['\ud83d\ude00\ud83d\ude01', "\xF0\x9F\x98\x80\xF0\x9F\x98\x81"];
        yield 'high then plain escape'  => ['\ud800\u0041', self::REPLACEMENT . 'A'];
        yield 'high then high'          => ['\ud800\ud800', self::REPLACEMENT . self::REPLACEMENT];
        yield 'high then below low'     => ['\ud800\udbff', self::REPLACEMENT . self::REPLACEMENT];
        yield 'high at end'             => ['\udbff', self::REPLACEMENT];
        yield 'low surrogate'           => ['\udc00', self::REPLACEMENT];
        yield 'last low surrogate'      => ['\udfff', self::REPLACEMENT];
        yield 'raw byte'                => ['\xFF', "\xFF"];
        yield 'two raw bytes'           => ['\x41\x42', 'AB'];
        yield 'short hex'               => ['\x4', '\x4'];
        yield 'short unicode'           => ['\u12', '\u12'];
        yield 'bad unicode digit'       => ['\u12G4', '\u12G4'];
        yield 'short long unicode'      => ['\U0001F60', '\U0001F60'];
        yield 'bad hex digit'           => ['\x4G', '\x4G'];
        yield 'digit escape unknown'    => ['\1', '\1'];
        yield 'nul escape'              => ['\0', "\x00"];
        yield 'text around escapes'     => ['a\nb\tc', "a\nb\tc"];
        yield 'adjacent escapes'        => ['\n\n', "\n\n"];
        yield 'unknown then known'      => ['\q\n', "\\q\n"];
        yield 'lone backslash'          => ['\\', '\\'];
        yield 'text then lone slash'    => ['ab\\', 'ab\\'];
        yield 'escaped backslash text'  => ['\\\\n', '\n'];
        yield 'tail after escape'       => ['\tend', "\tend"];
        yield 'prefix before escape'    => ['pre\tpost', "pre\tpost"];
        yield 'blank body'              => ['', ''];
        yield 'u at end'                => ['x\u', 'x\u'];
        yield 'capital u at end'        => ['x\U', 'x\U'];
    }

    #[DataProvider('numericEscapeProvider')]
    public function testDecodeNumericEscapes(string $source, string $expected): void
    {
        self::assertSame($expected, StringLiteral::decode($source));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function scanDoubleProvider(): iterable
    {
        yield 'from zero'                  => ['xyz"', 0, 3];
        yield 'immediately closed'         => ['""', 1, 1];
        yield 'escaped backslash'          => ['"a\\\\"', 1, 4];
        yield 'escaped quote then close'   => ['"\""', 1, 3];
        yield 'escaped paren is no interp' => ['"\\\\(x"', 1, 5];
        yield 'empty interpolation'        => ['"\(1)"', 1, 5];
        yield 'interpolation then text'    => ['"\(1)ab" tail', 1, 7];
        yield 'two interpolations'         => ['"\(1)\(2)"', 1, 9];
        yield 'nested paren pair'          => ['"\((1))"', 1, 7];
        yield 'interpolation with quote'   => ['"\("x")"', 1, 7];
        yield 'quote after other text'     => ['"ab\ncd"', 1, 7];
    }

    #[DataProvider('scanDoubleProvider')]
    public function testScanDoubleFindsTheClosingQuote(string $source, int $start, int $expected): void
    {
        self::assertSame($expected, StringLiteral::scanDouble($source, $start));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unterminatedProvider(): iterable
    {
        yield 'no closing quote'      => ['"xyz', 1];
        yield 'trailing backslash'    => ['"a\\', 1];
        yield 'escaped closing quote' => ['"a\"', 1];
        yield 'offset opening quote'  => ['xx"xyz', 3];
    }

    #[DataProvider('unterminatedProvider')]
    public function testScanDoubleReportsUnterminatedStringsAtTheOpeningQuote(string $source, int $start): void
    {
        try {
            StringLiteral::scanDouble($source, $start);
            self::fail('an unterminated string must be rejected');
        } catch (ExpressionSyntaxException $exception) {
            self::assertSame('Bad expression, unterminated string', $exception->getMessage());
            self::assertSame($start - 1, $exception->offset);
        }
    }

    public function testScanDoubleReportsAnOpenInterpolationAtItsBody(): void
    {
        foreach (['"a\(1', '"a\('] as $source) {
            try {
                StringLiteral::scanDouble($source, 1);
                self::fail('an open interpolation must be rejected');
            } catch (ExpressionSyntaxException $exception) {
                self::assertSame(self::NO_CLOSING_PAREN, $exception->getMessage());
                self::assertSame(4, $exception->offset);
            }
        }
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function skipInterpolationProvider(): iterable
    {
        yield 'bare'                     => ['1) rest', 0, 2];
        yield 'nested paren groups'      => ['(1)) rest', 0, 4];
        yield 'deeper nesting'           => ['((1)(2))) x', 0, 9];
        yield 'offset start'             => ['xx(1)y) z', 2, 7];
        yield 'quoted paren'             => ['"a)" + 1) rest', 0, 9];
        yield 'quoted open paren'        => ['"(" ) rest', 0, 5];
        yield 'single quoted paren'      => ["')' ) x", 0, 5];
        yield 'single quoted then close' => ["'a') b", 0, 4];
        yield 'escaped quote inside'     => ['"a\"b)" ) c', 0, 9];
        yield 'nested interpolation'     => ['"\(1)") z', 0, 7];
    }

    #[DataProvider('skipInterpolationProvider')]
    public function testSkipInterpolationReturnsTheIndexAfterTheClosingParen(string $source, int $start, int $expected): void
    {
        self::assertSame($expected, StringLiteral::skipInterpolation($source, $start));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unmatchedProvider(): iterable
    {
        yield 'nothing closes'      => ['((1)', 0];
        yield 'unterminated single' => ["'xyz", 0];
        yield 'single quote at end' => ["'", 0];
        yield 'offset open paren'   => ['xx(1', 2];
        yield 'blank source'        => ['', 0];
        yield 'only past the end'   => ['xyz', 3];
    }

    #[DataProvider('unmatchedProvider')]
    public function testSkipInterpolationReportsTheStart(string $source, int $start): void
    {
        try {
            StringLiteral::skipInterpolation($source, $start);
            self::fail('an unmatched paren must be rejected');
        } catch (ExpressionSyntaxException $exception) {
            self::assertSame(self::NO_CLOSING_PAREN, $exception->getMessage());
            self::assertSame($start, $exception->offset);
        }
    }

    /**
     * @return iterable<string, array{string, ?list<string|array{string, int}>}>
     */
    public static function splitProvider(): iterable
    {
        yield 'blank text'                  => ['', []];
        yield 'two interpolations'          => ['a\(.x)b\(.y)c', ['a', ['.x', 3], 'b', ['.y', 9], 'c']];
        yield 'escape decoded in literal'   => ['a\nq\(.x)', ["a\nq", ['.x', 6]]];
        yield 'nested call parens'          => ['\(f(1))', [['f(1)', 2]]];
        yield 'trailing backslash'          => ['xyz\\', ['xyz\\']];
        yield 'adjacent interpolations'     => ['\(.a)\(.b)', [['.a', 2], ['.b', 7]]];
        yield 'quoted paren in source'      => ['\(.a + ")")x', [['.a + ")"', 2], 'x']];
        yield 'escape before interpolation' => ['x\ty\(.z)', ["x\ty", ['.z', 6]]];
        yield 'interpolation then text'     => ['\(.a) tail', [['.a', 2], ' tail']];
        yield 'only text'                   => ['solo', ['solo']];
        yield 'backslash after interpolation' => ['\(.a)\\', [['.a', 2], '\\']];
        yield 'paren right at the end'      => ['a\\(', null];
    }

    /**
     * @param ?list<string|array{string, int}> $expected null when the body must be rejected
     */
    #[DataProvider('splitProvider')]
    public function testSplitSeparatesLiteralTextFromInterpolations(string $body, ?array $expected): void
    {
        if (null === $expected) {
            $this->expectException(ExpressionSyntaxException::class);
        }

        self::assertSame($expected, StringLiteral::split($body));
    }
}
