<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression\Parser;

use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Limits\NestingLimit;
use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\Parser\PrattParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * An expression nested deeper than {@see NestingLimit::MAX_DEPTH} levels is a syntax error, whether the nesting
 * comes from brackets, from a right-associative operator, from a chain the parser builds in a loop or from
 * string interpolations: each of them becomes an AST as deep, which the evaluator walks recursively. The
 * outermost expression is level 0 and every bracket, operator, postfix step or nested construct adds one.
 *
 * Deep parses run on a large fiber stack, so a coverage driver's native frames cannot overflow the process
 * stack.
 *
 * @internal
 */
#[CoversClass(PrattParser::class)]
#[Medium]
final class PrattParserNestingLimitTest extends TestCase
{
    private const string TOO_DEEP = 'Bad expression, nested deeper than 10000 levels';

    private const int LINEAR_PARSE_BUDGET_NANOSECONDS = 4_000_000_000;

    #[DataProvider('shapes')]
    public function testNestingAtTheLimitParses(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . self::nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels) . $suffix;

        self::assertCount(1, self::parse($source));
    }

    #[DataProvider('shapes')]
    public function testNestingPastTheLimitIsASyntaxError(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . self::nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels + 1) . $suffix;

        try {
            self::parse($source);
            self::fail('expected a syntax error');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(self::TOO_DEEP, $expressionSyntaxException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, int}> fixed prefix, text repeated
     *                                                                               before the leaf, the leaf, text repeated after it, fixed suffix, levels the fixed text adds
     */
    public static function shapes(): iterable
    {
        yield 'collections'          => ['', '[', '1', ']', '', 0];
        yield 'parentheses'          => ['', '(', '1', ')', '', 0];
        yield 'object values'        => ['', '{"a": ', '1', '}', '', 0];
        yield 'object keys'          => ['', '{', '1', ': 1}', '', 0];
        yield 'pipes'                => ['', '1 | ', '1', '', '', 0];
        yield 'unions'               => ['', '1, ', '1', '', '', 0];
        yield 'additions'            => ['', '1 + ', '1', '', '', 0];
        yield 'alternatives'         => ['', '1 // ', '1', '', '', 0];
        yield 'assignments'          => ['', '.a = ', '1', '', '', 0];
        yield 'field chains'         => ['', '', '.a', '.a', '', 0];
        yield 'index chains'         => ['', '', '.', '[0]', '', 0];
        yield 'juxtaposed words'     => ['', '', '.', ' x', '', 0];
        yield 'elif chains'          => ['if 1 then 1 ', 'elif 1 then 1 ', '', '', 'end', 1];
        yield 'reduce sources'       => ['', 'reduce ', '.', ' as $x (0; 1)', '', 0];
        yield 'bindings'             => ['', '. as $x | ', '1', '', '', 0];
        yield 'string interpolation' => ['', '"\(', '1', ')"', '', 0];
    }

    public function testSiblingsDoNotAddUpToTheLimit(): void
    {
        // `(A, B) | C`: A sits under the pipe and the union, two levels down
        $deep = self::nest('[', '1', ']', NestingLimit::MAX_DEPTH - 2);

        self::assertCount(1, self::parse($deep . ', ' . $deep . ' | ' . $deep));
    }

    #[DataProvider('wrappers')]
    public function testAnOperatorAfterADeepOperandPushesItOneLevelDown(string $wrapper): void
    {
        self::assertCount(1, self::parse(self::nest('[', '1', ']', NestingLimit::MAX_DEPTH - 1) . $wrapper));

        try {
            self::parse(self::nest('[', '1', ']', NestingLimit::MAX_DEPTH) . $wrapper);
            self::fail('expected a syntax error');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(self::TOO_DEEP, $expressionSyntaxException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappers(): iterable
    {
        yield 'union'           => [', 1'];
        yield 'pipe'            => [' | 1'];
        yield 'addition'        => [' + 1'];
        yield 'binding'         => [' as $x | 1'];
        yield 'postfix step'    => ['[0]'];
        yield 'juxtaposed word' => [' x'];
    }

    public function testAnInterpolationCountsTowardsTheLevelsAroundIt(): void
    {
        $inner = self::nest('[', '1', ']', NestingLimit::MAX_DEPTH - 1);

        self::assertCount(1, self::parse('"\(' . $inner . ')"'));

        try {
            self::parse('"\(' . $inner . ')" | 1');
            self::fail('expected a syntax error');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame(self::TOO_DEEP, $expressionSyntaxException->getMessage());
        }
    }

    public function testADeeplyInterpolatedStringParsesInLinearTime(): void
    {
        $source = self::nest('"\(', '1', ')"', NestingLimit::MAX_DEPTH);
        $start  = hrtime(true);

        self::parse($source);

        // re-scanning the inner text at every level took minutes at this depth
        self::assertLessThan(self::LINEAR_PARSE_BUDGET_NANOSECONDS, hrtime(true) - $start);
    }

    /**
     * @return non-empty-list<ExpressionNodeInterface> the parsed expression
     */
    private static function parse(string $source): array
    {
        return EvaluationStack::run(static fn (): array => [new PrattParser(new ExpressionLexer()->tokenize($source))->parseAll()]);
    }

    private static function nest(string $open, string $leaf, string $close, int $depth): string
    {
        return str_repeat($open, $depth) . $leaf . str_repeat($close, $depth);
    }
}
