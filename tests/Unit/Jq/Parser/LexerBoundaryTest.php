<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Token;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Escape decoding, comment continuation and error positions of the lexer.
 *
 * @internal
 */
final class LexerBoundaryTest extends TestCase
{
    private const string REPLACEMENT = "\u{fffd}";

    #[DataProvider('escapeProvider')]
    public function testUnicodeEscapes(string $source, string $expected): void
    {
        $tokens = new Lexer()->tokenize($source);

        self::assertCount(4, $tokens);
        self::assertSame($expected, $tokens[1]->text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeProvider(): iterable
    {
        $r = self::REPLACEMENT;

        yield 'lowest pair'                   => ['"\ud800\udc00"', "\u{10000}"];
        yield 'highest pair'                  => ['"\udbff\udfff"', "\u{10ffff}"];
        yield 'plane one character'           => ['"\ud808\udf45"', "\u{12345}"];
        yield 'lowest low surrogate'          => ['"\ud83d\udc00"', "\u{1f400}"];
        yield 'highest low surrogate'         => ['"\ud83d\udfff"', "\u{1f7ff}"];
        yield 'smiley'                        => ['"\ud83d\ude01"', "\u{1f601}"];
        yield 'high then plain escape'        => ['"\ud83d\u0041"', $r . 'A'];
        yield 'high then plain text'          => ['"\ud83dxuabcd"', $r . 'xuabcd'];
        yield 'text before lone high'         => ['"ab\ud83dx"', 'ab' . $r . 'x'];
        yield 'text before lone low'          => ['"ab\ude00"', 'ab' . $r];
        yield 'text before unpaired high'     => ['"ab\ud83d\u0041"', 'ab' . $r . 'A'];
        yield 'text before pair'              => ['"ab\ud83d\ude00cd"', "ab\u{1f600}cd"];
        yield 'two byte lowest'               => ['"\u0080"', "\u{80}"];
        yield 'two byte highest'              => ['"\u07ff"', "\u{7ff}"];
        yield 'three byte lowest'             => ['"\u0800"', "\u{800}"];
        yield 'three byte highest'            => ['"\uffff"', "\u{ffff}"];
        yield 'below surrogates'              => ['"\ud7ff"', "\u{d7ff}"];
        yield 'above surrogates'              => ['"\ue000"', "\u{e000}"];
        yield 'ascii'                         => ['"\u007f"', "\x7f"];
    }

    /**
     * @param list<string> $expected token positions as type@line:column
     */
    #[DataProvider('commentProvider')]
    public function testPositionsAroundComments(string $source, array $expected): void
    {
        $at = static fn (Token $token): string => $token->type->value . '@' . $token->line . ':' . $token->column;

        self::assertSame($expected, array_map($at, new Lexer()->tokenize($source)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commentProvider(): iterable
    {
        $first   = 'number@1:1';
        $line2   = 'eof@2:2';
        $third   = 'number@3:1';
        $line3   = 'eof@3:2';

        yield 'continued by lf'              => ["1 # a \\\n2", [$first, $line2]];
        yield 'continuation then blank line' => ["1 # a \\\n\n2", [$first, $third, $line3]];
        yield 'backslash before text'        => ["1 # a \\x\n2", [$first, 'number@2:1', $line2]];
        yield 'continued by crlf'            => ["1 # a \\\r\n2", [$first, $line2]];
        yield 'crlf continuation then blank' => ["1 # a \\\r\n\n2", [$first, $third, $line3]];
        yield 'backslash at end'             => ['1 # a \\', [$first, 'eof@1:8']];
        yield 'backslash cr at end'          => ["1 # a \\\r", [$first, 'eof@1:9']];
        yield 'cr backslash cr at end'       => ["1 # \\\r\\\r", [$first, 'eof@1:9']];
        yield 'two continuations'            => ["1 # a \\\n b \\\n c\n2", [$first, 'number@4:1', 'eof@4:2']];
        yield 'continuation line has text'   => ["1 # a \\\n 3 4\n2", [$first, $third, $line3]];
    }

    #[DataProvider('errorProvider')]
    public function testErrorPositions(string $source, string $message): void
    {
        try {
            new Lexer()->tokenize($source);
        } catch (JqCompileException $jqCompileException) {
            self::assertSame($message, $jqCompileException->getMessage());

            return;
        }

        self::fail('expected JqCompileException');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'end after high surrogate backslash' => [
            '"\ud83d\\',
            'syntax error, unexpected end of file at <top-level>, line 1, column 9:',
        ];
        yield 'unterminated string on line two' => [
            "\n\"abc",
            'syntax error, unexpected end of file at <top-level>, line 2, column 5:',
        ];
        yield 'unterminated after multiline string chunk' => [
            "\"a\nbc\\(1",
            'syntax error, unexpected end of file at <top-level>, line 2, column 6:',
        ];
        yield 'unterminated string on line three' => [
            "1\n2\n  \"x",
            'syntax error, unexpected end of file at <top-level>, line 3, column 5:',
        ];
        yield 'bad escape after newline in string' => [
            "\"a\n\\v\"",
            'Invalid escape at line 1, column 4 (while parsing \'"\v"\') at <top-level>, line 2, column 1:',
        ];
    }
}
