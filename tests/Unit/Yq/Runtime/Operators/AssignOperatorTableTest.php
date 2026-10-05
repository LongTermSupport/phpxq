<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\AssignOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Assignment, update and arithmetic assignment, whether the assigned nodes are shared or copied, and the
 * property setters. A row is expression, input and expected output separated by an arrow; a return sign
 * stands for a newline.
 *
 * @internal
 */
#[CoversClass(AssignOperator::class)]
final class AssignOperatorTableTest extends TestCase
{
    private const string ARROW = '➜';

    private const string RETURN = '⏎';

    #[DataProvider('successProvider')]
    public function testEvaluates(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input));
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function successProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            .a = .x.y➜a: 1⏎➜a: null⏎
            .a |= .x.y➜a: 1⏎➜a: 1⏎
            .a += .x.y➜a: 1⏎➜a: 1⏎
            .a += empty➜a: 1⏎➜a: 1⏎
            .a -= empty➜a: 1⏎➜a: 1⏎
            .a |= empty➜a: 1⏎➜a: 1⏎
            .a = empty➜a: 1⏎➜a: null⏎
            {"x": 1} as $v | .a = $v | .a.x = 2 | $v➜a: 1⏎➜x: 2⏎
            {"x": 1} as $v | (.a, .b) = $v | .a.x = 2 | $v➜a: 1⏎➜x: 1⏎
            {"x": 1} as $v | (.a, .b) = $v | .a.x = 2 | .b➜a: 1⏎➜x: 1⏎
            .a = .b | .a.x = 2 | .b➜a: 1⏎b: {x: 1}⏎➜{x: 1}⏎
            (.a, .c) = .b | .a.x = 2 | .b➜a: 1⏎b: {x: 1}⏎c: 1⏎➜{x: 1}⏎
            .a = .b | .b.x = 2➜a: 1⏎b: {x: 1}⏎➜a: {x: 1}⏎b: {x: 2}⏎
            .a = {"x": 1} | .a.x = 2 | .a➜a: 1⏎➜x: 2⏎
            .a |= {"x": 1} | .a.x = 2 | .a➜a: 1⏎➜x: 2⏎
            .a += [1] | .a[0] = 2 | .a➜a: []⏎➜[2]⏎
            .a =c 2➜a: !horse 1⏎➜a: !!int 2⏎
            .a = 2➜a: !horse 1⏎➜a: !horse 2⏎
            .a |= 2➜a: !horse 1⏎➜a: !horse 2⏎
            .a += 2➜a: !horse 1⏎➜a: !horse 3⏎
            .a tag = .x.y➜a: 1⏎➜a: 1⏎
            .a tag |= . + "x"➜a: 1⏎➜a: !<1x> 1⏎
            .a style |= "double"➜a: cat⏎➜a: "cat"⏎
            .a foot_comment = "f"➜a: 1⏎➜a: 1⏎# f⏎
            .a foot_comment |= "f"➜a: 1⏎➜a: 1⏎# f⏎
            .a line_comment |= "l" + .➜a: 1⏎➜a: 1 # l1⏎
            .a comments = "f"➜a: 1⏎➜a: 1 # f⏎# f⏎⏎# f⏎
            . foot_comment = "f"➜a: 1⏎➜a: 1⏎# f⏎
            .a line_comment = ""➜a: 1 # y⏎➜a: 1⏎
            . head_comment = ""➜# x⏎⏎a: 1⏎➜a: 1⏎
            . head_comment = "z"➜# x⏎⏎a: 1⏎➜# z⏎a: 1⏎
            . foot_comment = ""➜a: 1⏎⏎# foot⏎➜a: 1⏎
            . comments = ""➜# x⏎⏎a: 1⏎⏎# foot⏎➜a: 1⏎
            .a head_comment = ""➜# x⏎⏎a: 1⏎➜# x⏎⏎a: 1⏎
            . | head_comment = ""➜# x⏎⏎a: 1⏎➜# x⏎⏎a: 1⏎
            .a style = "flow"➜a: [1, 2]⏎➜a: [1, 2]⏎
            .a style = "flow"➜a:⏎  - 1⏎➜a: [1]⏎
            .a style = "double"➜a:⏎  - 1⏎➜a:⏎  - 1⏎
            .a style = "double"➜a: {x: 1}⏎➜a:⏎  x: 1⏎
            .a style = "tagged"➜a: 1⏎➜a: !!int 1⏎
            .a style = ""➜a: "1"⏎➜a: "1"⏎
            .a style = "single"➜a: x⏎➜a: 'x'⏎
            .a style = "literal"➜a: x⏎➜a: |-⏎  x⏎
            .a style = "folded"➜a: x⏎➜a: >-⏎  x⏎
            .a style = "bogus"➜a: "x"⏎➜a: x⏎
            .a anchor = "x" | .b alias = "x"➜a: 1⏎b: 2⏎➜a: &x 1⏎b: *x⏎
            .b alias = ""➜a: 1⏎b: 2⏎➜a: 1⏎b: 2⏎
            TABLE);
    }

    public function testAnUnknownAliasIsRejected(): void
    {
        try {
            YqHarness::run('.b alias = "nope"', "a: 1\nb: 2\n");
            self::fail('the alias has no anchor');
        } catch (EvaluationException $evaluationException) {
            self::assertSame('Could not find anchor nope', $evaluationException->getMessage());
        }
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
