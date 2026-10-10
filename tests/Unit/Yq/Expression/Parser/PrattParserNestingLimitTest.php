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
 * comes from brackets, from a right-associative operator, from a nested construct or from string
 * interpolations. The outermost expression is level 0 and each of them adds one. A chain the parser builds in a
 * loop (`|`, `,`, `+`, `.a`, `[0]`, juxtaposed words) is not nesting; a left-deep one counts towards
 * {@see NestingLimit::MAX_TREE_DEPTH} instead, and a union chain parses as a balanced tree at any length.
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

    private const string TOO_LONG = 'Bad expression, tree deeper than 100000 levels, counting chained operations';

    private const string INDEX_STEP = '[0]';

    private const int LINEAR_PARSE_BUDGET_NANOSECONDS = 4_000_000_000;

    private const int LONG_CHAIN = 30000;

    private const string INTERPOLATION_OPEN = '"\(';

    private const string INTERPOLATION_CLOSE = ')"';

    #[DataProvider('shapes')]
    public function testNestingAtTheLimitParses(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . $this->nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels) . $suffix;

        self::assertCount(1, $this->parse($source));
    }

    #[DataProvider('shapes')]
    public function testNestingPastTheLimitIsASyntaxError(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . $this->nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels + 1) . $suffix;

        self::assertSame(self::TOO_DEEP, $this->syntaxError($source));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, int}> fixed prefix, text repeated
     *                                                                              before the leaf, the leaf, text repeated after it, fixed suffix, levels the fixed text adds
     */
    public static function shapes(): iterable
    {
        yield 'collections'          => ['', '[', '1', ']', '', 0];
        yield 'parentheses'          => ['', '(', '1', ')', '', 0];
        yield 'object values'        => ['', '{"a": ', '1', '}', '', 0];
        yield 'object keys'          => ['', '{', '1', ': 1}', '', 0];
        yield 'assignments'          => ['', '.a = ', '1', '', '', 0];
        yield 'elif chains'          => ['if 1 then 1 ', 'elif 1 then 1 ', '', '', 'end', 1];
        yield 'reduce sources'       => ['', 'reduce ', '.', ' as $x (0; 1)', '', 0];
        yield 'bindings'             => ['', '. as $x | ', '1', '', '', 0];
        yield 'string interpolation' => ['', self::INTERPOLATION_OPEN, '1', self::INTERPOLATION_CLOSE, '', 0];
    }

    public function testSiblingsDoNotAddUpToTheLimit(): void
    {
        // `(A, B) | C`: A sits under the pipe and the union, two levels down
        $deep = $this->nest('[', '1', ']', NestingLimit::MAX_DEPTH - 2);

        self::assertCount(1, $this->parse($deep . ', ' . $deep . ' | ' . $deep));
    }

    /**
     * A chain the parser builds in a loop is not nesting, however long: Go yq accepts these.
     */
    #[DataProvider('chains')]
    public function testALongChainParses(string $prefix, string $link, string $last): void
    {
        self::assertCount(1, $this->parse($prefix . str_repeat($link, self::LONG_CHAIN) . $last));
    }

    /**
     * @return iterable<string, array{string, string, string}> text before the chain, a repeated link, the end
     */
    public static function chains(): iterable
    {
        yield 'collection elements' => ['[', '0, ', '0]'];
        yield 'unions'              => ['', '1, ', '1'];
        yield 'pipes'               => ['', '1 | ', '1'];
        yield 'additions'           => ['', '1 + ', '1'];
        yield 'alternatives'        => ['', '1 // ', '1'];
        yield 'conjunctions'        => ['', 'true and ', 'true'];
        yield 'field chains'        => ['', '.a', ''];
        yield 'index chains'        => ['.', '[0]', ''];
        yield 'juxtaposed words'    => ['.', ' x', ''];
    }

    /**
     * A chain is left-deep, and PHP frees a left-deep tree recursively on the native stack, which overflows near
     * 700,000 levels: the tree, chains included, is capped at {@see NestingLimit::MAX_TREE_DEPTH} levels.
     */
    #[DataProvider('leftDeepChains')]
    public function testAChainPastTheTreeLimitIsASyntaxError(string $prefix, string $link, string $last): void
    {
        // one link more than the limit allows even where the first link is the chain's operand (`.a.a`)
        $source = $prefix . str_repeat($link, NestingLimit::MAX_TREE_DEPTH + 2) . $last;

        self::assertSame(self::TOO_LONG, $this->syntaxError($source));
    }

    /**
     * @return iterable<string, array{string, string, string}> text before the chain, a repeated link, the end
     */
    public static function leftDeepChains(): iterable
    {
        foreach (self::chains() as $name => $chain) {
            if (!str_contains($chain[1], ',')) {
                yield $name => $chain;
            }
        }
    }

    public function testAUnionChainPastTheTreeLimitParsesAsABalancedTree(): void
    {
        self::assertCount(1, $this->parse('[' . str_repeat('0, ', 2 * NestingLimit::MAX_TREE_DEPTH) . '0]'));
    }

    public function testAChainAtTheTreeLimitParses(): void
    {
        // the leaf `.` sits under MAX_TREE_DEPTH index steps
        self::assertCount(1, $this->parse('.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH)));
        self::assertSame(self::TOO_LONG, $this->syntaxError('.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH + 1)));
    }

    public function testNestingAndChainsAddUpToTheTreeLimit(): void
    {
        $nested = $this->nest('[', '.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH - 100), ']', 100);

        self::assertCount(1, $this->parse($nested));
        self::assertSame(self::TOO_LONG, $this->syntaxError('[' . $nested . ']'));
    }

    public function testAChainInsideAnInterpolationCountsTowardsTheTreeLimit(): void
    {
        $string = self::INTERPOLATION_OPEN . '.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH - 1) . self::INTERPOLATION_CLOSE;

        self::assertCount(1, $this->parse($string));
        self::assertSame(self::TOO_LONG, $this->syntaxError($string . self::INDEX_STEP));
    }

    #[DataProvider('wrappers')]
    public function testAChainAfterAnOperandAtTheLimitParses(string $wrapper): void
    {
        self::assertCount(1, $this->parse($this->nest('[', '1', ']', NestingLimit::MAX_DEPTH) . str_repeat($wrapper, self::LONG_CHAIN)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappers(): iterable
    {
        yield 'union'           => [', 1'];
        yield 'pipe'            => [' | 1'];
        yield 'addition'        => [' + 1'];
        yield 'postfix step'    => ['[0]'];
        yield 'juxtaposed word' => [' x'];
    }

    public function testAnInterpolationCountsTowardsTheLevelsAroundIt(): void
    {
        $string = $this->nest(self::INTERPOLATION_OPEN, $this->nest('[', '1', ']', NestingLimit::MAX_DEPTH - 1), self::INTERPOLATION_CLOSE, 1);

        self::assertCount(1, $this->parse($string));
        self::assertSame(self::TOO_DEEP, $this->syntaxError('[' . $string . ']'));
    }

    public function testADeeplyInterpolatedStringParsesInLinearTime(): void
    {
        $source = $this->nest(self::INTERPOLATION_OPEN, '1', self::INTERPOLATION_CLOSE, NestingLimit::MAX_DEPTH);
        $start  = hrtime(true);

        $this->parse($source);

        // re-scanning the inner text at every level took minutes at this depth
        self::assertLessThan(self::LINEAR_PARSE_BUDGET_NANOSECONDS, hrtime(true) - $start);
    }

    /**
     * @return non-empty-list<ExpressionNodeInterface> the parsed expression
     */
    private function parse(string $source): array
    {
        return EvaluationStack::run(static fn (): array => [new PrattParser(new ExpressionLexer()->tokenize($source))->parseAll()]);
    }

    private function syntaxError(string $source): string
    {
        try {
            $this->parse($source);
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            return $expressionSyntaxException->getMessage();
        }

        self::fail('expected a syntax error');
    }

    private function nest(string $open, string $leaf, string $close, int $depth): string
    {
        return str_repeat($open, $depth) . $leaf . str_repeat($close, $depth);
    }
}
