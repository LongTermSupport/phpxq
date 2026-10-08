<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Ast\NodeInterface;
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
 * comes from brackets, from a right-recursive operator or from a left-deep chain the parser builds in a loop:
 * each of them becomes an AST as deep, which the compiler and the evaluator walk recursively. The outermost
 * expression is level 0 and every bracket, operator, postfix step or nested construct adds one.
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

    #[DataProvider('shapes')]
    public function testNestingAtTheLimitParses(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . self::nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels) . $suffix;

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    #[DataProvider('shapes')]
    public function testNestingPastTheLimitIsASyntaxError(string $prefix, string $open, string $leaf, string $close, string $suffix, int $fixedLevels): void
    {
        $source = $prefix . self::nest($open, $leaf, $close, NestingLimit::MAX_DEPTH - $fixedLevels + 1) . $suffix;

        try {
            self::parse($source);
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertStringStartsWith(self::TOO_DEEP, $jqCompileException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, int}> fixed prefix, text repeated
     *                                                                               before the leaf, the leaf, text repeated after it, fixed suffix, levels the fixed text adds
     */
    public static function shapes(): iterable
    {
        yield 'array constructors'   => ['', '[', '1', ']', '', 0];
        yield 'parentheses'          => ['', '(', '1', ')', '', 0];
        yield 'object values'        => ['', '{a:', '1', '}', '', 0];
        yield 'negations'            => ['', '-', '1', '', '', 0];
        yield 'pipes'                => ['', '1|', '1', '', '', 0];
        yield 'commas'               => ['', '1,', '1', '', '', 0];
        yield 'additions'            => ['', '1+', '1', '', '', 0];
        yield 'alternatives'         => ['', '1//', '1', '', '', 0];
        yield 'field chains'         => ['', '', '.a', '.a', '', 0];
        yield 'optional chains'      => ['', '', '.', '?', '', 0];
        yield 'index chains'         => ['', '', '.', '[0]', '', 0];
        yield 'object value pipes'   => ['{a:', '1|', '1', '', '}', 1];
        yield 'try bodies'           => ['', 'try ', '1', '', '', 0];
        yield 'negated try body'     => ['try ', '-', '1', '', '', 1];
        yield 'elif chains'          => ['if 1 then 1 ', 'elif 1 then 1 ', '', '', 'end', 1];
        yield 'array patterns'       => ['. as ', '[', '$x', ']', ' | 1', 0];
        yield 'object patterns'      => ['. as ', '{a:', '$x', '}', ' | 1', 0];
        yield 'string interpolation' => ['', '"\(', '1', ')"', '', 0];
        yield 'reduce sources'       => ['', 'reduce ', '.', ' as $x (0; 1)', '', 0];
        // inside brackets, so that the outermost definition is local rather than a top-level one
        yield 'local definitions'    => ['[', 'def f: ', '1', '; 1', ']', 1];
        yield 'labels'               => ['', 'label $out | ', '1', '', '', 0];
    }

    public function testTheLevelCountStartsAgainForEachParse(): void
    {
        $parser = new Parser(new Lexer());
        $source = self::nest('[', '1', ']', NestingLimit::MAX_DEPTH);

        [$first, $second] = EvaluationStack::run(static fn (): array => [$parser->parse($source), $parser->parse($source)]);

        self::assertInstanceOf(NodeInterface::class, $first->body);
        self::assertInstanceOf(NodeInterface::class, $second->body);
    }

    public function testSiblingsDoNotAddUpToTheLimit(): void
    {
        // `(A, B) | C`: A sits under the pipe and the comma, two levels down
        $deep   = self::nest('[', '1', ']', NestingLimit::MAX_DEPTH - 2);
        $source = $deep . ',' . $deep . '|' . $deep;

        self::assertInstanceOf(NodeInterface::class, self::parse($source)->body);
    }

    #[DataProvider('wrappers')]
    public function testAnOperatorAfterADeepOperandPushesItOneLevelDown(string $wrapper): void
    {
        self::assertInstanceOf(NodeInterface::class, self::parse(self::nest('[', '1', ']', NestingLimit::MAX_DEPTH - 1) . $wrapper)->body);

        try {
            self::parse(self::nest('[', '1', ']', NestingLimit::MAX_DEPTH) . $wrapper);
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertStringStartsWith(self::TOO_DEEP, $jqCompileException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrappers(): iterable
    {
        yield 'comma'        => [', 1'];
        yield 'pipe'         => [' | 1'];
        yield 'addition'     => [' + 1'];
        yield 'binding'      => [' as $x | 1'];
        yield 'postfix step' => ['[0]'];
        yield 'optional'     => ['?'];
    }

    public function testTheErrorPointsAtTheFirstTokenPastTheLimit(): void
    {
        $source = self::nest('[', '1', ']', NestingLimit::MAX_DEPTH + 1);

        try {
            self::parse($source);
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            // the leaf follows MAX_DEPTH + 1 brackets, so it sits in column MAX_DEPTH + 2
            self::assertSame(self::TOO_DEEP . (NestingLimit::MAX_DEPTH + 2) . ':', $jqCompileException->getMessage());
        }
    }

    private static function parse(string $source): Program
    {
        return EvaluationStack::run(static fn (): Program => new Parser(new Lexer())->parse($source));
    }

    private static function nest(string $open, string $leaf, string $close, int $depth): string
    {
        return str_repeat($open, $depth) . $leaf . str_repeat($close, $depth);
    }
}
