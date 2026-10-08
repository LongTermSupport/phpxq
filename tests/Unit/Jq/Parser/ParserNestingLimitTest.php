<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Ast\Comma;
use LTS\PhpXq\Jq\Ast\NodeInterface;
use LTS\PhpXq\Jq\Ast\NumberLiteral;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Limits\NestingLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A program nested deeper than {@see NestingLimit::MAX_DEPTH} levels is a syntax error, whether the nesting
 * comes from brackets, from a right-recursive operator (`|`, `//`) or from a nested construct. The outermost
 * expression is level 0 and each of them adds one. A chain the parser builds in a loop (`,`, `+`, `.a`, `[0]`,
 * `?`) is not nesting; a left-deep one counts towards {@see NestingLimit::MAX_TREE_DEPTH} instead, and a comma
 * chain parses as a balanced tree at any length.
 *
 * Deep parses run on the evaluation fiber, as the CLI runs them, so a coverage driver's native frames cannot
 * overflow the process stack.
 *
 * @internal
 */
#[CoversClass(Parser::class)]
#[Medium]
final class ParserNestingLimitTest extends TestCase
{
    private const string TOO_DEEP = 'syntax error, program nested deeper than 10000 levels at <top-level>, line 1, column ';

    private const string TOO_LONG = 'syntax error, program tree deeper than 100000 levels, counting chained operations';

    private const int LONG_CHAIN = 30000;

    private const string INDEX_STEP = '[0]';

    private const string OBJECT_OPEN = '{a:';

    #[DataProvider('shapes')]
    public function testNestingAtTheLimitParses(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . $this->nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels) . $suffix;

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    #[DataProvider('shapes')]
    public function testNestingPastTheLimitIsASyntaxError(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        self::assertStringStartsWith(self::TOO_DEEP, $this->compileError($prefix . $this->nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels + 1) . $suffix));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, int}> fixed prefix, text repeated
     *                                                                              before the leaf, the leaf, text repeated after it, fixed suffix, levels the fixed text adds
     */
    public static function shapes(): iterable
    {
        yield 'array constructors'   => ['', '[', '1', ']', '', 0];
        yield 'parentheses'          => ['', '(', '1', ')', '', 0];
        yield 'object values'        => ['', self::OBJECT_OPEN, '1', '}', '', 0];
        yield 'negations'            => ['', '-', '1', '', '', 0];
        yield 'pipes'                => ['', '1|', '1', '', '', 0];
        yield 'alternatives'         => ['', '1//', '1', '', '', 0];
        yield 'object value pipes'   => [self::OBJECT_OPEN, '1|', '1', '', '}', 1];
        yield 'try bodies'           => ['', 'try ', '1', '', '', 0];
        yield 'negated try body'     => ['try ', '-', '1', '', '', 1];
        yield 'elif chains'          => ['if 1 then 1 ', 'elif 1 then 1 ', '', '', 'end', 1];
        yield 'array patterns'       => ['. as ', '[', '$x', ']', ' | 1', 0];
        yield 'object patterns'      => ['. as ', self::OBJECT_OPEN, '$x', '}', ' | 1', 0];
        yield 'string interpolation' => ['', '"\(', '1', ')"', '', 0];
        yield 'reduce sources'       => ['', 'reduce ', '.', ' as $x (0; 1)', '', 0];
        // inside brackets, so that the outermost definition is local rather than a top-level one
        yield 'local definitions'    => ['[', 'def f: ', '1', '; 1', ']', 1];
        yield 'labels'               => ['', 'label $out | ', '1', '', '', 0];
    }

    public function testTheLevelCountStartsAgainForEachParse(): void
    {
        $parser = new Parser(new Lexer());
        $source = $this->nest('[', '1', ']', NestingLimit::MAX_DEPTH);

        [$first, $second] = EvaluationStack::run(static fn (): array => [$parser->parse($source), $parser->parse($source)]);

        self::assertInstanceOf(NodeInterface::class, $first->body);
        self::assertInstanceOf(NodeInterface::class, $second->body);
    }

    public function testSiblingsDoNotAddUpToTheLimit(): void
    {
        // `(A, B) | C`: A sits under the pipe and the comma, two levels down
        $deep   = $this->nest('[', '1', ']', NestingLimit::MAX_DEPTH - 2);
        $source = $deep . ',' . $deep . '|' . $deep;

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    /**
     * A chain the parser builds in a loop is not nesting, however long: jq 1.6 accepts these.
     */
    #[DataProvider('chains')]
    public function testALongChainParses(string $prefix, string $link, string $last): void
    {
        $source = $prefix . str_repeat($link, self::LONG_CHAIN) . $last;

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    /**
     * @return iterable<string, array{string, string, string}> text before the chain, a repeated link, the end
     */
    public static function chains(): iterable
    {
        yield 'array elements'  => ['[', '0,', '0]'];
        yield 'commas'          => ['', '1,', '1'];
        yield 'additions'       => ['', '1+', '1'];
        yield 'subtractions'    => ['', '1-', '1'];
        yield 'products'        => ['', '1*', '1'];
        yield 'conjunctions'    => ['', 'true and ', 'true'];
        yield 'field chains'    => ['', '.a', ''];
        yield 'optional chains' => ['.', '?', ''];
        yield 'index chains'    => ['.', self::INDEX_STEP, ''];
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

        self::assertStringStartsWith(self::TOO_LONG, $this->compileError($source));
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

    public function testACommaChainPastTheTreeLimitParsesAsABalancedTree(): void
    {
        $source = '[' . str_repeat('0,', 2 * NestingLimit::MAX_TREE_DEPTH) . '0]';

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    public function testAChainAtTheTreeLimitParses(): void
    {
        // the leaf `.` sits under MAX_TREE_DEPTH index steps
        self::assertInstanceOf(NodeInterface::class, self::parse('.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH))->body);
        self::assertStringStartsWith(self::TOO_LONG, $this->compileError('.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH + 1)));
    }

    public function testNestingAndChainsAddUpToTheTreeLimit(): void
    {
        $nested = $this->nest('[', '.' . str_repeat(self::INDEX_STEP, NestingLimit::MAX_TREE_DEPTH - 100), ']', 100);

        self::assertInstanceOf(NodeInterface::class, self::parse($nested)->body);
        self::assertStringStartsWith(self::TOO_LONG, $this->compileError('[' . $nested . ']'));
    }

    #[DataProvider('wrappers')]
    public function testAChainAfterAnOperandAtTheLimitParses(string $wrapper): void
    {
        $source = $this->nest('[', '1', ']', NestingLimit::MAX_DEPTH) . str_repeat($wrapper, self::LONG_CHAIN);

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappers(): iterable
    {
        yield 'comma'        => [', 1'];
        yield 'addition'     => [' + 1'];
        yield 'postfix step' => [self::INDEX_STEP];
        yield 'optional'     => ['?'];
    }

    public function testAnOperandOfAChainCountsItsOwnNesting(): void
    {
        self::assertStringStartsWith(self::TOO_DEEP, $this->compileError('1, ' . $this->nest('[', '1', ']', NestingLimit::MAX_DEPTH + 1) . ', 1'));
    }

    public function testCommaOperandsKeepTheirOrder(): void
    {
        $items = [];
        $node  = self::parse('1, 2, 3, 4, 5')->body;
        $stack = [$node];
        while ([] !== $stack) {
            $next = array_pop($stack);
            if ($next instanceof Comma) {
                $stack[] = $next->right;
                $stack[] = $next->left;

                continue;
            }

            self::assertInstanceOf(NumberLiteral::class, $next);
            $items[] = $next->text;
        }

        self::assertSame(['1', '2', '3', '4', '5'], $items);
    }

    public function testTheErrorPointsAtTheFirstTokenPastTheLimit(): void
    {
        // the leaf follows MAX_DEPTH + 1 brackets, so it sits in column MAX_DEPTH + 2
        self::assertSame(self::TOO_DEEP . (NestingLimit::MAX_DEPTH + 2) . ':', $this->compileError($this->nest('[', '1', ']', NestingLimit::MAX_DEPTH + 1)));
    }

    private static function parse(string $source): Program
    {
        return EvaluationStack::run(static fn (): Program => new Parser(new Lexer())->parse($source));
    }

    private function compileError(string $source): string
    {
        try {
            self::parse($source);
        } catch (JqCompileException $jqCompileException) {
            return $jqCompileException->getMessage();
        }

        self::fail('expected a compile error');
    }

    private function nest(string $open, string $leaf, string $close, int $depth): string
    {
        return str_repeat($open, $depth) . $leaf . str_repeat($close, $depth);
    }
}
