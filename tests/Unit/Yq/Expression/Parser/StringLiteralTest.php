<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression\Parser;

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

    #[DataProvider('numericEscapeProvider')]
    public function testDecodeNumericEscapes(string $source, string $expected): void
    {
        self::assertSame($expected, StringLiteral::decode($source));
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
        yield 'escaped backslash text'  => ['\\\n', '\n'];
        yield 'tail after escape'       => ['\tend', "\tend"];
        yield 'prefix before escape'    => ['pre\tpost', "pre\tpost"];
        yield 'blank body'              => ['', ''];
        yield 'u at end'                => ['x\u', 'x\u'];
        yield 'capital u at end'        => ['x\U', 'x\U'];
    }
}
