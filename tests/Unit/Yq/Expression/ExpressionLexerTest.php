<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression;

use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\ExpressionToken;
use LTS\PhpXq\Yq\Expression\ExpressionTokenKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExpressionLexer::class)]
final class ExpressionLexerTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('tokenProvider')]
    public function testTokens(string $source, array $expected): void
    {
        $tokens = new ExpressionLexer()->tokenize($source);

        self::assertSame($expected, array_map(
            static fn (ExpressionToken $token): string => $token->kind->name . ':' . $token->text,
            $tokens,
        ));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tokenProvider(): iterable
    {
        yield 'empty'              => ['', ['EndOfInput:']];
        yield 'whitespace'         => [" \t\n ", ['EndOfInput:']];
        yield 'identity'           => ['.', ['Dot:.', 'EndOfInput:']];
        yield 'field'              => ['.a', ['Dot:.', 'Word:a', 'EndOfInput:']];
        yield 'nested field'       => ['.a.b-c', ['Dot:.', 'Word:a', 'Dot:.', 'Word:b-c', 'EndOfInput:']];
        yield 'numeric field'      => ['.a.0', ['Dot:.', 'Word:a', 'Dot:.', 'Word:0', 'EndOfInput:']];
        yield 'glob field'         => ['.a*', ['Dot:.', 'Word:a*', 'EndOfInput:']];
        yield 'xml attribute'      => ['.+@c', ['Dot:.', 'Word:+@c', 'EndOfInput:']];
        yield 'quoted field'       => ['."a b"', ['Dot:.', 'String:a b', 'EndOfInput:']];
        yield 'optional'           => ['.a?', ['Dot:.', 'Word:a', 'Question:?', 'EndOfInput:']];
        yield 'brackets'           => ['.[1]', ['Dot:.', 'LeftBracket:[', 'Number:1', 'RightBracket:]', 'EndOfInput:']];
        yield 'recursive'          => ['..', ['DotDot:..', 'EndOfInput:']];
        yield 'recursive keys'     => ['...', ['DotDotDot:...', 'EndOfInput:']];
        yield 'pipe and comma'     => ['.a | .b, .c', ['Dot:.', 'Word:a', 'Operator:|', 'Dot:.', 'Word:b', 'Operator:,', 'Dot:.', 'Word:c', 'EndOfInput:']];
        yield 'assign family'      => ['= |= += -= /= %= //', ['Operator:=', 'Operator:|=', 'Operator:+=', 'Operator:-=', 'Operator:/=', 'Operator:%=', 'Operator://', 'EndOfInput:']];
        yield 'comparison'         => ['== != < <= > >=', ['Operator:==', 'Operator:!=', 'Operator:<', 'Operator:<=', 'Operator:>', 'Operator:>=', 'EndOfInput:']];
        yield 'arithmetic'         => ['+ - * / %', ['Operator:+', 'Operator:-', 'Operator:*', 'Operator:/', 'Operator:%', 'EndOfInput:']];
        yield 'multiply modifiers' => ['*+ *? *d *n *c *?+ *=c *=', ['Operator:*+', 'Operator:*?', 'Operator:*d', 'Operator:*n', 'Operator:*c', 'Operator:*?+', 'Operator:*=c', 'Operator:*=', 'EndOfInput:']];
        yield 'modifier backs off' => ['*dd', ['Operator:*', 'Word:dd', 'EndOfInput:']];
        yield 'assign clobber'     => ['=c .b', ['Operator:=c', 'Dot:.', 'Word:b', 'EndOfInput:']];
        yield 'assign word'        => ['=cat', ['Operator:=', 'Word:cat', 'EndOfInput:']];
        yield 'numbers'            => ['1 2.5 0x1F 0o17 1e3', ['Number:1', 'Number:2.5', 'Number:0x1F', 'Number:0o17', 'Number:1e3', 'EndOfInput:']];
        yield 'number then dot'    => ['1.a', ['Number:1', 'Dot:.', 'Word:a', 'EndOfInput:']];
        yield 'variable'           => ['$foo_1', ['Variable:foo_1', 'EndOfInput:']];
        yield 'words'              => ['select(.a)', ['Word:select', 'LeftParen:(', 'Dot:.', 'Word:a', 'RightParen:)', 'EndOfInput:']];
        yield 'format words'       => ['@base64d ~', ['Word:@base64d', 'Word:~', 'EndOfInput:']];
        yield 'punctuation'        => ['{a: 1; 2}', ['LeftBrace:{', 'Word:a', 'Colon::', 'Number:1', 'Semicolon:;', 'Number:2', 'RightBrace:}', 'EndOfInput:']];
        yield 'comment'            => [".a # note\n| .b", ['Dot:.', 'Word:a', 'Operator:|', 'Dot:.', 'Word:b', 'EndOfInput:']];
        yield 'comment at end'     => ['.a # note', ['Dot:.', 'Word:a', 'EndOfInput:']];
        yield 'double string'      => ['"a\nb\"c"', ['String:' . "a\nb\"c", 'EndOfInput:']];
        yield 'single string'      => ["'a\\nb'", ['String:a\nb', 'EndOfInput:']];
        yield 'dot space word'     => ['. style', ['Dot:.', 'Word:style', 'EndOfInput:']];
        yield 'unicode word'       => ['é', ['Word:é', 'EndOfInput:']];
    }

    public function testOffsets(): void
    {
        $tokens = new ExpressionLexer()->tokenize('.a  | "x"');

        self::assertSame([0, 1, 4, 6, 9], array_map(static fn (ExpressionToken $token): int => $token->offset, $tokens));
    }

    public function testInterpolatedStringIsRaw(): void
    {
        $tokens = new ExpressionLexer()->tokenize('"I like \(.v) and \"q\""');

        self::assertSame(ExpressionTokenKindEnum::String, $tokens[0]->kind);
        self::assertTrue($tokens[0]->raw);
        self::assertSame('', $tokens[0]->text);
        self::assertSame('I like ', $tokens[0]->parts[0]);
        self::assertSame(' and "q"', $tokens[0]->parts[2]);
    }

    public function testPlainStringIsNotRaw(): void
    {
        $tokens = new ExpressionLexer()->tokenize('"abc"');

        self::assertFalse($tokens[0]->raw);
    }

    public function testInterpolationHoldingQuotes(): void
    {
        $tokens = new ExpressionLexer()->tokenize('"a \(.b | "c") d" | .e');

        self::assertCount(3, $tokens[0]->parts);
        self::assertSame(' d', $tokens[0]->parts[2]);
        self::assertSame(ExpressionTokenKindEnum::Operator, $tokens[1]->kind);
    }

    #[DataProvider('errorProvider')]
    public function testErrors(string $source): void
    {
        $this->expectException(ExpressionSyntaxException::class);
        new ExpressionLexer()->tokenize($source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'unterminated double' => ['"abc'];
        yield 'unterminated single' => ["'abc"];
        yield 'lone bang'           => ['.a ! .b'];
        yield 'lone dollar'         => ['$'];
        yield 'backtick'            => ['`'];
        yield 'at sign alone'       => ['@ '];
    }

    public function testErrorMessageCarriesPosition(): void
    {
        try {
            new ExpressionLexer()->tokenize(".a\n  `");
            self::fail('expected a syntax exception');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(5, $expressionSyntaxException->offset);
            self::assertStringContainsString('2:3', $expressionSyntaxException->getMessage());
        }
    }
}
