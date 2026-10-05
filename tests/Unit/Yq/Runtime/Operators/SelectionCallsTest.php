<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\SelectionCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * select, not, has, contains, any/all, first/last, filter and with over documents. A row is expression,
 * input and expected output separated by an arrow; a return sign stands for a newline.
 *
 * @internal
 */
#[CoversClass(SelectionCalls::class)]
final class SelectionCallsTest extends TestCase
{
    private const string ARROW = '➜';

    private const string RETURN = '⏎';

    /**
     * @return Generator<string, array{string, string, string}>
     */
    private static function rows(string $table): Generator
    {
        foreach (explode("\n", trim($table)) as $line) {
            [$expression, $input, $expected] = explode(self::ARROW, $line);

            yield $line => [
                $expression,
                str_replace(self::RETURN, "\n", $input),
                str_replace(self::RETURN, "\n", $expected),
            ];
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function successProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            .a | any➜a: [true, false]⏎➜true⏎
            .a | all➜a: [true, false]⏎➜false⏎
            .a | all➜a: [true, true]⏎➜true⏎
            .a | any➜a: [false, false]⏎➜false⏎
            .a | any➜a: []⏎➜false⏎
            .a | all➜a: []⏎➜true⏎
            .a | any➜a: [null, false, 1]⏎➜true⏎
            .a | all➜a: [1, null]⏎➜false⏎
            .a | all➜a: [1, 2]⏎➜true⏎
            .a | any_c(. > 2)➜a: [1, 2, 3]⏎➜true⏎
            .a | any_c(. > 3)➜a: [1, 2, 3]⏎➜false⏎
            .a | all_c(. > 0)➜a: [1, 2, 3]⏎➜true⏎
            .a | all_c(. > 1)➜a: [1, 2, 3]⏎➜false⏎
            .a | any_c(. > 1)➜a: []⏎➜false⏎
            .a | all_c(. > 1)➜a: []⏎➜true⏎
            .a | any_c(empty)➜a: [1]⏎➜false⏎
            .a | all_c(empty)➜a: [1]⏎➜false⏎
            .a | any_c(.x)➜a: [{x: false}, {x: 1}]⏎➜true⏎
            .a | all_c(.x)➜a: [{x: false}, {x: 1}]⏎➜false⏎
            .a | any_c(.x, 0)➜a: [{x: 0}]⏎➜true⏎
            (.a, .b) | any➜a: [true]⏎b: [false]⏎➜true⏎false⏎
            .a | first➜a: [1, 2, 3]⏎➜1⏎
            .a | last➜a: [1, 2, 3]⏎➜3⏎
            .a | first➜a: []⏎➜
            .a | last➜a: []⏎➜
            .a | first(. > 1)➜a: [1, 2, 3]⏎➜2⏎
            .a | last(. < 3)➜a: [1, 2, 3]⏎➜2⏎
            .a | first(. > 5)➜a: [1, 2, 3]⏎➜
            .a | last(. > 5)➜a: [1, 2, 3]⏎➜
            .a | first➜a: {x: 1, y: 2}⏎➜1⏎
            .a | last➜a: {x: 1, y: 2}⏎➜2⏎
            .a | first➜a: 5⏎➜
            .a | first(.k)➜a: [{k: null}, {k: 7, n: 1}]⏎➜{k: 7, n: 1}⏎
            .a | last(.k)➜a: [{k: 1, n: 1}, {k: 2, n: 2}]⏎➜{k: 2, n: 2}⏎
            .a | first(. > 1, . > 0)➜a: [1, 2]⏎➜1⏎
            .a | filter(. > 1)➜a: [1, 2, 3]⏎➜- 2⏎- 3⏎
            .a | filter(. > 5)➜a: [1, 2, 3]⏎➜[]⏎
            .a | filter(.x)➜a: [{x: 1}, {y: 2}, {x: 3}]⏎➜- {x: 1}⏎- {x: 3}⏎
            .a | filter(. > 1)➜a: {p: 1, q: 2}⏎➜- 2⏎
            .a | filter(false, true)➜a: [1, 2]⏎➜- 1⏎- 2⏎
            with(.a; . += 1)➜a: 1⏎➜a: 2⏎
            with(.a.b; . = 5)➜a: {b: 1}⏎➜a: {b: 5}⏎
            with(.a[]; . *= 2)➜a: [1, 2]⏎➜a: [2, 4]⏎
            with(.a; . = 2) | .a➜a: 1⏎➜2⏎
            with(.x; . = 1)➜a: 1⏎➜a: 1⏎x: 1⏎
            with(.a, .b; . = 0)➜a: 1⏎b: 2⏎➜a: 0⏎b: 0⏎
            .a | has("x")➜a: {x: 1}⏎➜true⏎
            .a | has("y")➜a: {x: 1}⏎➜false⏎
            .a | has(0)➜a: [5]⏎➜true⏎
            .a | has(1)➜a: [5]⏎➜false⏎
            .a | has(-1)➜a: [5]⏎➜false⏎
            .a | has(1)➜a: [5, 6]⏎➜true⏎
            .a | has(1.5)➜a: [5, 6]⏎➜false⏎
            .a | has("x")➜a: [5]⏎➜false⏎
            .a | has("x")➜a: 5⏎➜false⏎
            .a | has(0)➜a: 5⏎➜false⏎
            .a | has("x", "y")➜a: {x: 1}⏎➜true⏎false⏎
            .a | has(1)➜a: {1: z}⏎➜true⏎
            .a | has(empty)➜a: {x: 1}⏎➜
            .a | has("b")➜a: {x: 1, b: 2}⏎➜true⏎
            .a | contains("bar")➜a: foobar⏎➜true⏎
            .a | contains("baz")➜a: foobar⏎➜false⏎
            .a | contains("")➜a: foobar⏎➜true⏎
            .a | contains("foobar")➜a: foobar⏎➜true⏎
            .a | contains("foobarx")➜a: foobar⏎➜false⏎
            .a | contains(1)➜a: [1]⏎➜false⏎
            .a | contains([1])➜a: 1⏎➜false⏎
            .a | contains(null)➜a: null⏎➜true⏎
            .a | contains("x")➜a: null⏎➜false⏎
            .a | contains(null)➜a: x⏎➜false⏎
            .a | contains(1.0)➜a: 1⏎➜true⏎
            .a | contains(2)➜a: 1⏎➜false⏎
            .a | contains(1)➜a: 21⏎➜true⏎
            .a | contains([1])➜a: [1, 2]⏎➜true⏎
            .a | contains([3])➜a: [1, 2]⏎➜false⏎
            .a | contains([1, 3])➜a: [1, 2]⏎➜false⏎
            .a | contains([])➜a: [1, 2]⏎➜true⏎
            .a | contains([2, 1])➜a: [1, 2]⏎➜true⏎
            .a | contains([2])➜a: [1, 2]⏎➜true⏎
            .a | contains([[1]])➜a: [[1, 2], [3]]⏎➜true⏎
            .a | contains([[4]])➜a: [[1, 2], [3]]⏎➜false⏎
            .a | contains([[3]])➜a: [[1, 2], [3]]⏎➜true⏎
            .a | contains({"x": 1})➜a: {x: 1, y: 2}⏎➜true⏎
            .a | contains({"z": 1})➜a: {x: 1, y: 2}⏎➜false⏎
            .a | contains({"x": 2})➜a: {x: 1, y: 2}⏎➜false⏎
            .a | contains({})➜a: {x: 1, y: 2}⏎➜true⏎
            .a | contains({"y": 2, "x": 1})➜a: {x: 1, y: 2}⏎➜true⏎
            .a | contains({"y": 2})➜a: {x: 1, y: 2}⏎➜true⏎
            .a | contains({"x": {"y": 1}})➜a: {x: {y: 1, z: 2}}⏎➜true⏎
            .a | contains({"x": {"y": 3}})➜a: {x: {y: 1, z: 2}}⏎➜false⏎
            .a | contains({"x": 1})➜a: {x: {y: 1}}⏎➜false⏎
            .a | contains("a")➜a: [a]⏎➜false⏎
            .a | contains("ab", "zz")➜a: abc⏎➜true⏎false⏎
            .[] | select(. > 1)➜- 1⏎- 2⏎- 3⏎➜2⏎3⏎
            .[] | select(true, true)➜- 1⏎➜1⏎
            .[] | select(false, true)➜- 1⏎➜1⏎
            .[] | select(false, false)➜- 1⏎➜
            .[] | select(empty)➜- 1⏎➜
            .[] | select(.a)➜- {a: 1}⏎- {b: 2}⏎➜{a: 1}⏎
            ., (.[] | select(.k))➜- {a: 1}⏎➜- {a: 1}⏎
            ., (.[] | select(.k.m))➜- {a: 1}⏎➜- {a: 1}⏎
            ., any_c(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎false⏎
            ., all_c(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎false⏎
            ., first(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎
            ., filter(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎[]⏎
            .a | not➜a: true⏎➜false⏎
            .a | not➜a: false⏎➜true⏎
            .a | not➜a: null⏎➜true⏎
            .a | not➜a: 0⏎➜false⏎
            .[] | not➜- true⏎- false⏎➜false⏎true⏎
            TABLE);
    }

    #[DataProvider('successProvider')]
    public function testEvaluates(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input));
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function failureProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            any➜a: 1⏎➜Cannot apply any to !!map
            all➜a: 1⏎➜Cannot apply all to !!map
            .a | any_c(. > 1)➜a: 5⏎➜Cannot apply any_c to !!int
            .a | all_c(. > 1)➜a: x⏎➜Cannot apply all_c to !!str
            TABLE);
    }

    #[DataProvider('failureProvider')]
    public function testRejectsTheInput(string $expression, string $input, string $message): void
    {
        try {
            YqHarness::run($expression, $input);
            self::fail('the call does not apply');
        } catch (EvaluationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function missingArgumentProvider(): Generator
    {
        foreach (['select', 'has', 'contains', 'any_c', 'all_c', 'filter', 'with'] as $name) {
            yield $name => [$name . '()'];
        }
    }

    #[DataProvider('missingArgumentProvider')]
    public function testCallsThatNeedArgumentsRejectNone(string $expression): void
    {
        $this->expectException(EvaluationException::class);

        YqHarness::run('.a | ' . $expression, "a: [1]\n");
    }
}
