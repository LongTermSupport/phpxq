<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression;

use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Limits\NestingLimit;
use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\ExpressionToken;
use LTS\PhpXq\Yq\Expression\ExpressionTokenKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Double-quoted strings: where they end, and the decoded literal text and interpolation tokens a raw string
 * carries. An interpolation is tokenized in the same pass as its string, so its tokens carry offsets in the
 * whole expression.
 *
 * @internal
 */
#[CoversClass(ExpressionLexer::class)]
#[Medium]
final class ExpressionLexerInterpolationTest extends TestCase
{
    private const string NO_CLOSING_PAREN = 'Bad expression, could not find matching `)`';

    private const string UNTERMINATED = 'Bad expression, unterminated string';

    /**
     * @param list<string|list<string>> $expected literal text, or the `text@offset` of each interpolation token
     */
    #[DataProvider('partsProvider')]
    public function testARawStringCarriesItsParts(string $source, array $expected): void
    {
        $token = new ExpressionLexer()->tokenize($source)[0];

        self::assertTrue($token->raw);
        self::assertSame($expected, self::render($token));
    }

    /**
     * @return iterable<string, array{string, list<string|list<string>>}>
     */
    public static function partsProvider(): iterable
    {
        yield 'two interpolations'            => ['"a\(.x)b\(.y)c"', ['a', ['.@4', 'x@5', '@6'], 'b', ['.@10', 'y@11', '@12'], 'c']];
        yield 'escape decoded in literal'     => ['"a\nq\(.x)"', ["a\nq", ['.@7', 'x@8', '@9']]];
        yield 'nested call parens'            => ['"\(f(1))"', [['f@3', '(@4', '1@5', ')@6', '@7']]];
        yield 'adjacent interpolations'       => ['"\(.a)\(.b)"', [['.@3', 'a@4', '@5'], ['.@8', 'b@9', '@10']]];
        yield 'quoted paren in source'        => ['"\(.a + ")")x"', [['.@3', 'a@4', '+@6', ')@8', '@11'], 'x']];
        yield 'single quoted paren'           => ['"\(\')\')"', [[')@3', '@6']]];
        yield 'interpolation then text'       => ['"\(.a) tail"', [['.@3', 'a@4', '@5'], ' tail']];
        yield 'backslash after interpolation' => ['"\(.a)\\\\"', [['.@3', 'a@4', '@5'], '\\']];
        yield 'escaped backslash before paren' => ['"\\\\(x"', ['\(x']];
        yield 'empty interpolation'           => ['"\()"', [['@3']]];
        yield 'nested string interpolation'   => ['"\("\(1)")"', [['@3', '@9']]];
    }

    public function testANestedInterpolationCarriesItsOwnParts(): void
    {
        $inner = new ExpressionLexer()->tokenize('"a\("b\(1)")"')[0]->parts[1];

        self::assertIsArray($inner);
        self::assertSame(['b', ['1@8', '@9']], self::render($inner[0]));
    }

    #[DataProvider('endProvider')]
    public function testTheTokenAfterAStringStartsAfterItsClosingQuote(string $source, int $nextOffset): void
    {
        self::assertSame($nextOffset, new ExpressionLexer()->tokenize($source)[1]->offset);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function endProvider(): iterable
    {
        yield 'plain'                    => ['"abc" |', 6];
        yield 'escaped quote'            => ['"a\"b" |', 7];
        yield 'escaped backslash'        => ['"a\\\\" |', 6];
        yield 'escaped paren'            => ['"\\\\(x" |', 7];
        yield 'interpolation'            => ['"\(1)ab" |', 9];
        yield 'two interpolations'       => ['"\(1)\(2)" |', 11];
        yield 'nested paren pair'        => ['"\((1))" |', 9];
        yield 'interpolation with quote' => ['"x \(.a | "q)") y" |', 19];
    }

    #[DataProvider('unterminatedProvider')]
    public function testAnUnterminatedStringIsReportedAtItsOpeningQuote(string $source, int $quote): void
    {
        try {
            new ExpressionLexer()->tokenize($source);
            self::fail('an unterminated string must be rejected');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(self::UNTERMINATED, $expressionSyntaxException->getMessage());
            self::assertSame($quote, $expressionSyntaxException->offset);
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unterminatedProvider(): iterable
    {
        yield 'no closing quote'           => ['"xyz', 0];
        yield 'trailing backslash'         => ['"a\\', 0];
        yield 'escaped closing quote'      => ['"a\"', 0];
        yield 'after other tokens'         => ['.a | "xyz', 5];
        yield 'inside an interpolation'    => ['"a\(.b + "c', 9];
    }

    #[DataProvider('openInterpolationProvider')]
    public function testAnOpenInterpolationIsReportedAtItsBody(string $source): void
    {
        try {
            new ExpressionLexer()->tokenize($source);
            self::fail('an open interpolation must be rejected');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(self::NO_CLOSING_PAREN, $expressionSyntaxException->getMessage());
            self::assertSame(4, $expressionSyntaxException->offset);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openInterpolationProvider(): iterable
    {
        yield 'source left open' => ['"a\(1'];
        yield 'nothing after'    => ['"a\('];
        yield 'paren left open'  => ['"a\((1)'];
    }

    public function testInterpolationsNestUpToTheLimit(): void
    {
        $source = str_repeat('"\(', NestingLimit::MAX_DEPTH) . '1' . str_repeat(')"', NestingLimit::MAX_DEPTH);

        $tokens = EvaluationStack::run(static fn (): array => new ExpressionLexer()->tokenize($source));

        self::assertSame(ExpressionTokenKindEnum::EndOfInput, $tokens[1]->kind);
    }

    public function testInterpolationsNestedPastTheLimitAreRejected(): void
    {
        $depth  = NestingLimit::MAX_DEPTH + 1;
        $source = str_repeat('"\(', $depth) . '1' . str_repeat(')"', $depth);

        try {
            EvaluationStack::run(static fn (): array => new ExpressionLexer()->tokenize($source));
            self::fail('expected a syntax error');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame('Bad expression, nested deeper than 10000 levels', $expressionSyntaxException->getMessage());
            self::assertSame(3 * NestingLimit::MAX_DEPTH + 1, $expressionSyntaxException->offset);
        }
    }

    /**
     * @return list<string|list<string>>
     */
    private static function render(ExpressionToken $token): array
    {
        return array_map(
            static fn (array|string $part): array|string => \is_string($part)
                ? $part
                : array_map(static fn (ExpressionToken $inner): string => $inner->text . '@' . $inner->offset, $part),
            $token->parts,
        );
    }
}
