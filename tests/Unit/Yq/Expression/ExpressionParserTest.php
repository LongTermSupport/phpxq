<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression;

use LTS\PhpXq\Yq\Expression\ExpressionParser;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExpressionParser::class)]
final class ExpressionParserTest extends TestCase
{
    public function testEmptyExpressionIsIdentity(): void
    {
        self::assertSame('.', AstDumper::dump(new ExpressionParser()->parse('')));
    }

    public function testLexerErrorsPropagate(): void
    {
        $this->expectException(ExpressionSyntaxException::class);
        new ExpressionParser()->parse('.a `');
    }

    public function testParserErrorsPropagate(): void
    {
        $this->expectException(ExpressionSyntaxException::class);
        new ExpressionParser()->parse('(.a');
    }

    public function testInterpolationLexerErrorOffsetIsAbsolute(): void
    {
        try {
            new ExpressionParser()->parse('.a | "xy \(`)"');
            self::fail('expected a syntax exception');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(11, $expressionSyntaxException->offset);
        }
    }

    #[DataProvider('referenceExpressionProvider')]
    public function testReferenceExpressionsParse(string $expression): void
    {
        $node = new ExpressionParser()->parse($expression);

        self::assertNotSame('', AstDumper::dump($node));
    }

    /**
     * Every distinct expression of the vendored reference documentation must parse.
     *
     * @return iterable<string, array{string}>
     */
    public static function referenceExpressionProvider(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../../Conformance/Yq/fixtures/cases.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);
        $seen = [];
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $expression = $case['expression'] ?? '';
            self::assertIsString($expression);
            if (isset($seen[$expression])) {
                continue;
            }

            $seen[$expression] = true;

            yield 'expression: ' . $expression => [$expression];
        }
    }
}
