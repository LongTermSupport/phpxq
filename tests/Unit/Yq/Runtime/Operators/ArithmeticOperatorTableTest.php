<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\ArithmeticOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arithmetic over documents. A row is expression, input and expected output separated by an arrow; a return
 * sign stands for a newline.
 *
 * @internal
 */
#[CoversClass(ArithmeticOperator::class)]
final class ArithmeticOperatorTableTest extends TestCase
{
    private const string ARROW = '➜';

    private const string RETURN = '⏎';

    #[DataProvider('successProvider')]
    public function testComputes(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input));
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function successProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            .a + .b➜a: 1⏎b: 2⏎➜3⏎
            .a + .b➜a: 1.5⏎b: 2⏎➜3.5⏎
            .a + .b➜a: 1⏎b: 1.0⏎➜2⏎
            . + 1➜5⏎➜6⏎
            .x + 1➜a: 1⏎➜1⏎
            1 + .x➜a: 1⏎➜1⏎
            .a + .b➜a: [1, 2]⏎b: [3]⏎➜[1, 2, 3]⏎
            .a + 3➜a: [1, 2]⏎➜[1, 2, 3]⏎
            .a + null➜a: [1, 2]⏎➜[1, 2]⏎
            null + .a➜a: [1, 2]⏎➜[1, 2]⏎
            .a + .b➜a: {x: 1}⏎b: {y: 2}⏎➜{x: 1, y: 2}⏎
            .a + .b➜a: {x: 1}⏎b: {x: 2}⏎➜{x: 2}⏎
            .a + .b➜a: {x: 1, y: 2}⏎b: {x: 3, z: 4}⏎➜{x: 3, y: 2, z: 4}⏎
            .a + .b➜a: {x: 1}⏎b: null⏎➜{x: 1}⏎
            .a + .b➜a: null⏎b: {x: 1}⏎➜{x: 1}⏎
            .a + .b➜a: "x"⏎b: "y"⏎➜xy⏎
            .a + .b➜a: x⏎b: 2⏎➜x2⏎
            .a + .b➜a: 1⏎b: x⏎➜1x⏎
            .a - .b➜a: 5⏎b: 2⏎➜3⏎
            .a - .b➜a: 5⏎b: 2.5⏎➜2.5⏎
            .a - .b➜a: 1⏎b: 3⏎➜-2⏎
            .a - .b➜a: [1, 2, 3]⏎b: [2]⏎➜[1, 3]⏎
            .a - .b➜a: [1, 2, 3]⏎b: 2⏎➜[1, 3]⏎
            .a - .b➜a: [1, 2]⏎b: [1, 2]⏎➜[]⏎
            .a - .b➜a: [1, 2]⏎b: []⏎➜[1, 2]⏎
            .a - .b➜a: [1, 2]⏎b: null⏎➜[1, 2]⏎
            .a - .b➜a: [{x: 1}, {x: 2}]⏎b: [{x: 1}]⏎➜[{x: 2}]⏎
            .a - .b➜a: 5⏎b: null⏎➜5⏎
            .x - 1➜a: 1⏎➜
            .a * .b➜a: 2⏎b: 3⏎➜6⏎
            .a * .b➜a: 2⏎b: 3.5⏎➜7⏎
            .a * .b➜a: 2.5⏎b: 2⏎➜5⏎
            .a * .b➜a: ab⏎b: 3⏎➜ababab⏎
            .a * .b➜a: 3⏎b: ab⏎➜ababab⏎
            .a * .b➜a: ab⏎b: 0⏎➜⏎
            .a * .b➜a: ab⏎b: -1⏎➜⏎
            .a * .b➜a: ab⏎b: 2.9⏎➜abab⏎
            .a * .b➜a: ab⏎b: 1⏎➜ab⏎
            .a * .b➜a: null⏎b: 3⏎➜3⏎
            .a * .b➜a: 3⏎b: null⏎➜3⏎
            .x * 3➜a: 1⏎➜3⏎
            .a * .b➜a: {x: 1}⏎b: {y: 2}⏎➜{x: 1, y: 2}⏎
            .a * .b➜a: {x: {p: 1}}⏎b: {x: {q: 2}}⏎➜{x: {p: 1, q: 2}}⏎
            .a * .b➜a: {x: [1, 2]}⏎b: {x: [3]}⏎➜{x: [3]}⏎
            .a *+ .b➜a: {x: [1, 2]}⏎b: {x: [3]}⏎➜{x: [1, 2, 3]}⏎
            .a *d .b➜a: {x: [1, 2]}⏎b: {x: [3]}⏎➜{x: [3, 2]}⏎
            .a *n .b➜a: {x: 1}⏎b: {x: 2, y: 3}⏎➜{x: 1, y: 3}⏎
            .a *? .b➜a: {x: 1}⏎b: {x: 2, y: 3}⏎➜{x: 2}⏎
            .a * .b➜a: {x: 1}⏎b: 2⏎➜2⏎
            .a / .b➜a: 6⏎b: 4⏎➜1.5⏎
            .a / .b➜a: 6⏎b: 3⏎➜2⏎
            .a / .b➜a: 1⏎b: 0⏎➜+Inf⏎
            .a / .b➜a: -1⏎b: 0⏎➜-Inf⏎
            .a / .b➜a: 0⏎b: 0⏎➜NaN⏎
            .a / .b➜a: a,b⏎b: ","⏎➜- a⏎- b⏎
            .a / .b➜a: abc⏎b: ""⏎➜- a⏎- b⏎- c⏎
            .a / .b➜a: ""⏎b: ","⏎➜- ""⏎
            .a / .b➜a: a⏎b: ","⏎➜- a⏎
            .a / .b➜a: a,,b⏎b: ","⏎➜- a⏎- ""⏎- b⏎
            .a / .b➜a: é⏎b: ""⏎➜- é⏎
            .a / .b➜a: 4⏎b: 2.0⏎➜2⏎
            .x / 2➜a: 1⏎➜2⏎
            .a % .b➜a: 7⏎b: 4⏎➜3⏎
            .a % .b➜a: -7⏎b: 4⏎➜-3⏎
            .a % .b➜a: 7⏎b: -4⏎➜3⏎
            .a % .b➜a: 8⏎b: 4⏎➜0⏎
            .a % .b➜a: 7.5⏎b: 2⏎➜1.5⏎
            .a % .b➜a: 7⏎b: 2.5⏎➜2⏎
            .a % .b➜a: 5⏎b: 2.5⏎➜0⏎
            .a % .b➜a: 7⏎b: 0⏎➜NaN⏎
            .x % 2➜a: 1⏎➜2⏎
            .a + .b➜a: 2001-12-15T02:59:43Z⏎b: 1h⏎➜2001-12-15T03:59:43Z⏎
            .a + .b➜a: 2001-12-15T02:59:43Z⏎b: 1h30m⏎➜2001-12-15T04:29:43Z⏎
            .a - .b➜a: 2001-12-15T02:59:43Z⏎b: 1h⏎➜2001-12-15T01:59:43Z⏎
            .a + .b➜a: 2001-12-15T02:59:43+02:00⏎b: 90m⏎➜2001-12-15T04:29:43+02:00⏎
            .a + .b➜a: 2001-12-15⏎b: 24h⏎➜2001-12-16T00:00:00Z⏎
            .a + .b➜a: 2001-12-15⏎b: bad⏎➜2001-12-15bad⏎
            .a + .b➜a: x⏎b: 1h⏎➜x1h⏎
            .a + .b➜a: 2001-12-15T02:59:43Z⏎b: 0.5s⏎➜2001-12-15T02:59:43Z⏎
            .a + .b➜a: 2001-12-15T02:59:43Z⏎b: -1h⏎➜2001-12-15T01:59:43Z⏎
            with_dtf("2006-01-02"; .a + .b)➜a: 2001-12-15⏎b: 24h⏎➜2001-12-16⏎
            with_dtf("2006-01-02"; .a - .b)➜a: 2001-12-15⏎b: 24h⏎➜2001-12-14⏎
            (1, 2) + (10, 20)➜a: 1⏎➜11⏎21⏎12⏎22⏎
            [1, 2] | .[0] + .[1]➜a: 1⏎➜3⏎
            .a += .b➜a: 1⏎b: 2⏎➜a: 3⏎b: 2⏎
            .a += [3]➜a: [1, 2]⏎➜a: [1, 2, 3]⏎
            .a += 3➜a: [1, 2]⏎➜a: [1, 2, 3]⏎
            .a += {"z": 1}➜a: {x: 1}⏎➜a: {x: 1, z: 1}⏎
            .a -= [1]➜a: [1, 2]⏎➜a: [2]⏎
            .a -= 1➜a: 5⏎➜a: 4⏎
            .a *= 2➜a: 5⏎➜a: 10⏎
            .a *= {"c": 1}➜a: {b: 1}⏎➜a: {b: 1, c: 1}⏎
            .a /= 2➜a: 5⏎➜a: 2.5⏎
            .a %= 2➜a: 5⏎➜a: 1⏎
            .a += "x"➜a:⏎  - "p"⏎  - "q"⏎➜a:⏎  - "p"⏎  - "q"⏎  - "x"⏎
            .a += "x"➜a: ['p']⏎➜a: ['p', 'x']⏎
            .a += "x"➜a:⏎  - p⏎➜a:⏎  - p⏎  - x⏎
            .a += 1➜a: []⏎➜a: [1]⏎
            .a += .b➜a: [1]⏎b: [2]⏎➜a: [1, 2]⏎b: [2]⏎
            .. |= [] + .➜a: 1⏎➜- a:⏎    - 1⏎
            TABLE);
    }

    #[DataProvider('failureProvider')]
    public function testRejectsTheCombination(string $expression, string $input, string $message): void
    {
        try {
            YqHarness::run($expression, $input);
            self::fail('the operands do not combine');
        } catch (EvaluationException $evaluationException) {
            self::assertSame($message, $evaluationException->getMessage());
        }
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function failureProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            .a + .b➜a: {x: 1}⏎b: 1⏎➜!!int (scalar) cannot be added to a !!map (map)
            .a + .b➜a: 1⏎b: {x: 1}⏎➜!!map (map) cannot be added to a !!int (scalar)
            .a + .b➜a: 1⏎b: [1]⏎➜!!seq (seq) cannot be added to a !!int (scalar)
            .a - .b➜a: {x: 1}⏎b: 1⏎➜!!int (scalar) cannot be subtracted from !!map (map)
            .a - .b➜a: x⏎b: 1⏎➜!!int (scalar) cannot be subtracted from !!str (scalar)
            .a - .b➜a: 1⏎b: x⏎➜!!str (scalar) cannot be subtracted from !!int (scalar)
            .a - .b➜a: 1⏎b: [1]⏎➜!!seq (seq) cannot be subtracted from !!int (scalar)
            .a / .b➜a: x⏎b: 1⏎➜!!str (scalar) cannot be divided by !!int (scalar)
            .a / .b➜a: 1⏎b: x⏎➜!!int (scalar) cannot be divided by !!str (scalar)
            .a / .b➜a: [1]⏎b: 1⏎➜!!seq (seq) cannot be divided by !!int (scalar)
            .a / .b➜a: 1⏎b: null⏎➜!!int (scalar) cannot be divided by !!null (scalar)
            .a % .b➜a: x⏎b: 1⏎➜!!str (scalar) cannot be modded by !!int (scalar)
            .a % .b➜a: 1⏎b: x⏎➜!!int (scalar) cannot be modded by !!str (scalar)
            .a % .b➜a: 1⏎b: [1]⏎➜!!int (scalar) cannot be modded by !!seq (seq)
            TABLE);
    }

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
}
