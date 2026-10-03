<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Token;
use LTS\PhpXq\Jq\Parser\TokenTypeEnum;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LexerTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideSources')]
    public function testTokenizes(string $source, array $expected): void
    {
        self::assertSame($expected, $this->describe($source));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSources(): iterable
    {
        yield 'empty' => ['', ['eof']];
        yield 'whitespace only' => [" \t\r\n ", ['eof']];
        yield 'identity' => ['.', ['dot', 'eof']];
        yield 'recurse' => ['..', ['dotdot', 'eof']];
        yield 'field' => ['.foo', ['field:foo', 'eof']];
        yield 'field keyword' => ['.and.if', ['field:and', 'field:if', 'eof']];
        yield 'quoted field' => ['."foo"', ['dot', 'string-start', 'string-fragment:foo', 'string-end', 'eof']];
        yield 'dot space ident' => ['. foo', ['dot', 'ident:foo', 'eof']];
        yield 'index' => ['.[0]', ['dot', 'lbracket', 'number:0', 'rbracket', 'eof']];
        yield 'numbers' => [
            '1 1.5 1. .5 1e3 1E+10 1.5e-3 100000000000000000000',
            ['number:1', 'number:1.5', 'number:1.', 'number:.5', 'number:1e3', 'number:1E+10', 'number:1.5e-3', 'number:100000000000000000000', 'eof'],
        ];
        yield 'number then e without digits' => ['1e', ['number:1', 'ident:e', 'eof']];
        yield 'keywords' => [
            'def if then elif else end as reduce foreach try catch label import include module and or',
            ['def', 'if', 'then', 'elif', 'else', 'end', 'as', 'reduce', 'foreach', 'try', 'catch', 'label', 'import', 'include', 'module', 'and', 'or', 'eof'],
        ];
        yield 'not and break are idents' => ['not break __loc__', ['ident:not', 'ident:break', 'ident:__loc__', 'eof']];
        yield 'keyword prefix ident' => ['iffy defx', ['ident:iffy', 'ident:defx', 'eof']];
        yield 'namespaced' => ['a::b::c a::', ['ident:a::b::c', 'ident:a', 'colon', 'colon', 'eof']];
        yield 'variables' => ['$x $__loc__ $a::b $if', ['variable:x', 'variable:__loc__', 'variable:a::b', 'variable:if', 'eof']];
        yield 'formats' => ['@base64 @sh', ['format:base64', 'format:sh', 'eof']];
        yield 'format string' => ['@base64 "a"', ['format:base64', 'string-start', 'string-fragment:a', 'string-end', 'eof']];
        yield 'punctuation' => ['| , : ; ( ) [ ] { } ?', ['pipe', 'comma', 'colon', 'semicolon', 'lparen', 'rparen', 'lbracket', 'rbracket', 'lbrace', 'rbrace', 'question', 'eof']];
        yield 'operators' => [
            '= |= += -= *= /= %= //= == != < <= > >= + - * / % // ?//',
            ['assign', 'update-assign', 'plus-assign', 'minus-assign', 'star-assign', 'slash-assign', 'percent-assign', 'alt-assign', 'eq', 'neq', 'lt', 'le', 'gt', 'ge', 'plus', 'minus', 'star', 'slash', 'percent', 'alt', 'destruct-alt', 'eof'],
        ];
        yield 'alt then question' => ['.a?//1', ['field:a', 'destruct-alt', 'number:1', 'eof']];
        yield 'empty string' => ['""', ['string-start', 'string-end', 'eof']];
        yield 'simple escapes' => [
            '"a\"\\\\\/\b\f\n\r\tz"',
            ['string-start', "string-fragment:a\"\\/\x08\x0c\n\r\tz", 'string-end', 'eof'],
        ];
        yield 'unicode escape' => ['"\u00e9\u0041"', ['string-start', "string-fragment:\u{e9}A", 'string-end', 'eof']];
        yield 'surrogate pair' => ['"\ud83d\ude00"', ['string-start', "string-fragment:\u{1F600}", 'string-end', 'eof']];
        yield 'lone high surrogate' => ['"\ud83dx"', ['string-start', "string-fragment:\u{FFFD}x", 'string-end', 'eof']];
        yield 'lone low surrogate' => ['"\ude00"', ['string-start', "string-fragment:\u{FFFD}", 'string-end', 'eof']];
        yield 'interpolation' => [
            '"a\(1 + 2)b"',
            ['string-start', 'string-fragment:a', 'interp-start', 'number:1', 'plus', 'number:2', 'interp-end', 'string-fragment:b', 'string-end', 'eof'],
        ];
        yield 'interpolation only' => ['"\(.)"', ['string-start', 'interp-start', 'dot', 'interp-end', 'string-end', 'eof']];
        yield 'interpolation with parens' => [
            '"\((1))"',
            ['string-start', 'interp-start', 'lparen', 'number:1', 'rparen', 'interp-end', 'string-end', 'eof'],
        ];
        yield 'nested interpolation' => [
            '"a\("b\(.)c")d"',
            [
                'string-start', 'string-fragment:a', 'interp-start',
                'string-start', 'string-fragment:b', 'interp-start', 'dot', 'interp-end', 'string-fragment:c', 'string-end',
                'interp-end', 'string-fragment:d', 'string-end', 'eof',
            ],
        ];
        yield 'raw newline in string' => ["\"a\nb\" .", ['string-start', "string-fragment:a\nb", 'string-end', 'dot', 'eof']];
        yield 'comment' => ["1 # hi\n2", ['number:1', 'number:2', 'eof']];
        yield 'comment at eof' => ['1 # hi', ['number:1', 'eof']];
        yield 'comment continuation' => ["1 # hi \\\nstill\n2", ['number:1', 'number:2', 'eof']];
        yield 'comment continuation crlf' => ["1 # hi \\\r\nstill\r\n2", ['number:1', 'number:2', 'eof']];
        yield 'comment even backslashes ends' => ["1 # hi \\\\\n2", ['number:1', 'number:2', 'eof']];
        yield 'comment triple backslashes continues' => ["1 # hi \\\\\\\nstill\n2", ['number:1', 'number:2', 'eof']];
        yield 'comment cr ends' => ["1 # hi\r2", ['number:1', 'number:2', 'eof']];
        yield 'comment in string is text' => ['"# no"', ['string-start', 'string-fragment:# no', 'string-end', 'eof']];
    }

    public function testPositions(): void
    {
        $tokens = new Lexer()->tokenize("{a:\n  .foo,\n \"x\\(1)\" }");
        $at     = static fn (Token $t): string => $t->type->value . '@' . $t->line . ':' . $t->column;

        self::assertSame(
            [
                'lbrace@1:1', 'ident@1:2', 'colon@1:3', 'field@2:3', 'comma@2:7',
                'string-start@3:2', 'string-fragment@3:3', 'interp-start@3:4', 'number@3:6', 'interp-end@3:7',
                'string-end@3:8', 'rbrace@3:10', 'eof@3:11',
            ],
            array_map($at, $tokens),
        );
    }

    public function testPositionAfterMultilineString(): void
    {
        $tokens = new Lexer()->tokenize("\"a\nbc\" 1");

        self::assertSame(2, $tokens[3]->line);
        self::assertSame(5, $tokens[3]->column);
    }

    public function testPositionAfterComment(): void
    {
        $tokens = new Lexer()->tokenize("# c\n  1");

        self::assertSame(2, $tokens[0]->line);
        self::assertSame(3, $tokens[0]->column);
    }

    public function testLexerIsReusable(): void
    {
        $lexer  = new Lexer();
        $failed = false;
        try {
            $lexer->tokenize('"\(1');
        } catch (JqCompileException) {
            $failed = true;
        }

        self::assertTrue($failed);
        self::assertCount(2, $lexer->tokenize('.'));
    }

    #[DataProvider('provideErrors')]
    public function testErrors(string $source, string $message): void
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
    public static function provideErrors(): iterable
    {
        yield 'invalid escape' => [
            '"u\vw"',
            'Invalid escape at line 1, column 4 (while parsing \'"\v"\') at <top-level>, line 1, column 3:',
        ];
        yield 'invalid escape space' => [
            'include "\ "; 0',
            'Invalid escape at line 1, column 4 (while parsing \'"\ "\') at <top-level>, line 1, column 10:',
        ];
        yield 'short unicode escape' => [
            '"\u12"',
            'Invalid \uXXXX escape at line 1, column 6 (while parsing \'"\u12"\') at <top-level>, line 1, column 2:',
        ];
        yield 'bad hex unicode escape' => [
            '"\u12zz"',
            'Invalid characters in \uXXXX escape at line 1, column 8 (while parsing \'"\u12zz"\') at <top-level>, line 1, column 2:',
        ];
        yield 'unterminated string' => ['"abc', 'syntax error, unexpected end of file at <top-level>, line 1, column 5:'];
        yield 'unterminated interpolation' => ['"a\(1', 'syntax error, unexpected end of file at <top-level>, line 1, column 6:'];
        yield 'trailing backslash' => ['"abc\\', 'syntax error, unexpected end of file at <top-level>, line 1, column 6:'];
        yield 'unknown char' => ['1 ~ 2', 'syntax error, unexpected INVALID_CHARACTER at <top-level>, line 1, column 3:'];
        yield 'lone bang' => ['!', 'syntax error, unexpected INVALID_CHARACTER at <top-level>, line 1, column 1:'];
        yield 'lone dollar' => ['$ x', 'syntax error, unexpected INVALID_CHARACTER at <top-level>, line 1, column 1:'];
        yield 'lone at' => ['@', 'syntax error, unexpected INVALID_CHARACTER at <top-level>, line 1, column 1:'];
        yield 'error on line 2' => ["1\n  ~", 'syntax error, unexpected INVALID_CHARACTER at <top-level>, line 2, column 3:'];
    }

    /**
     * @return list<string>
     */
    private function describe(string $source): array
    {
        $out = [];
        foreach (new Lexer()->tokenize($source) as $token) {
            $carriesText = \in_array($token->type, [
                TokenTypeEnum::Number, TokenTypeEnum::Ident, TokenTypeEnum::Field, TokenTypeEnum::Variable,
                TokenTypeEnum::Format, TokenTypeEnum::StringFragment,
            ], true);
            $out[] = $carriesText ? $token->type->value . ':' . $token->text : $token->type->value;
        }

        return $out;
    }
}
