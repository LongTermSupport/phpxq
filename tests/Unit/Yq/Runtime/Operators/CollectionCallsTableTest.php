<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\CollectionCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * length, keys, to/from/with_entries, map, map_values, flatten, add, pivot, array_to_map and range. A row is
 * expression, input and expected output separated by an arrow; a return sign stands for a newline.
 *
 * @internal
 */
#[CoversClass(CollectionCalls::class)]
final class CollectionCallsTableTest extends TestCase
{
    private const string ARROW = '➜';

    private const string RETURN = '⏎';

    public function testNamesTheCollectionBuiltins(): void
    {
        self::assertSame(
            ['length', 'keys', 'to_entries', 'from_entries', 'with_entries', 'map', 'map_values', 'flatten', 'add', 'pivot', 'array_to_map', 'range'],
            new CollectionCalls()->names(),
        );
    }

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
            .a | to_entries➜a: {x: 1, <<: {x: 2, z: 3}}⏎➜- key: x⏎  value: 2⏎- key: z⏎  value: 3⏎
            .a | to_entries➜a: {x: 1, y: 2}⏎➜- key: x⏎  value: 1⏎- key: y⏎  value: 2⏎
            .a | to_entries➜a: [5, 6]⏎➜- key: 0⏎  value: 5⏎- key: 1⏎  value: 6⏎
            .a | to_entries➜a: ~⏎➜
            .a | to_entries | .[0].key➜a:⏎  # head⏎  x: 1 # line⏎➜x⏎
            .a | with_entries(.)➜a: {x: 1, <<: {x: 2, z: 3}}⏎➜x: 2⏎z: 3⏎
            .a | with_entries(select(.value > 1))➜a: {x: 1, y: 2}⏎➜y: 2⏎
            .a | with_entries(.value += 1)➜a: {x: 1, y: 2}⏎➜x: 2⏎y: 3⏎
            .a | with_entries(empty)➜a: {x: 1}⏎➜{}⏎
            .a | with_entries(.key |= "k" + .)➜a: {x: 1, y: 2}⏎➜kx: 1⏎ky: 2⏎
            .a | with_entries(.)➜a: [7, 8]⏎➜0: 7⏎1: 8⏎
            .a | with_entries(.)➜a: ~⏎➜
            .a | with_entries(.value.k)➜a: {x: {j: 1}}⏎➜{}⏎
            .a | with_entries(.value.k = 1)➜a: {x: {j: 1}}⏎➜x: {j: 1, k: 1}⏎
            (.a | with_entries(.value.k)) as $x | .➜a: {x: {j: 1}}⏎➜a: {x: {j: 1}}⏎
            .a | map(.)➜a: {x: 1, <<: {x: 2, z: 3}}⏎➜- 2⏎- 3⏎
            .a | map(. + 1)➜a: [1, 2]⏎➜[2, 3]⏎
            .a | map(. + 1)➜a:⏎  - 1⏎  - 2⏎➜- 2⏎- 3⏎
            .a | map(. + 1)➜a: {x: 1, y: 2}⏎➜- 2⏎- 3⏎
            .a | map(.)➜a: 5⏎➜
            .a | map(.k.m)➜a: [{k: 1}]⏎➜[]⏎
            .a | map(., .)➜a: [1]⏎➜[1, 1]⏎
            .a | map(empty)➜a: [1]⏎➜[]⏎
            .a | map(.k = 1)➜a: [{j: 1}]⏎➜[{j: 1, k: 1}]⏎
            (.a | map(.k)) as $x | .➜a: [{j: 1}]⏎➜a: [{j: 1}]⏎
            .a | map_values(. + 1)➜a: [1, 2]⏎➜[2, 3]⏎
            .a | map_values(. + 1)➜a: {x: 1, y: 2}⏎➜{x: 2, y: 3}⏎
            .a | map_values(.)➜a: {x: 1, <<: {x: 2, z: 3}}⏎➜{x: 1, <<: {x: 2, z: 3}}⏎
            .a | map_values(empty)➜a: {x: 1}⏎➜{x: 1}⏎
            .a | map_values(.k.m)➜a: {x: {k: 1}}⏎➜{x: {k: 1}}⏎
            .a | map_values(1, 2)➜a: {x: 5}⏎➜{x: 1}⏎
            .a | map_values(.k = 1)➜a: {x: {j: 1}}⏎➜{x: {j: 1, k: 1}}⏎
            .a | map_values(.k)➜a: {x: {j: 1}}⏎➜{x: {j: 1}}⏎
            (.a | map_values(.k)) as $x | .➜a: {x: {j: 1}}⏎➜a: {x: {j: 1}}⏎
            .a | from_entries➜a: [{key: x, value: 1}, {key: y}, {value: 3}, 5, {value: 4, key: z}]⏎➜x: 1⏎y: null⏎z: 4⏎
            .a | from_entries➜a: [{key: x, value: 1, extra: 9}]⏎➜x: 1⏎
            .a | to_entries | from_entries➜a: {x: 1, y: 2}⏎➜x: 1⏎y: 2⏎
            .a | length➜a: &x [1]⏎b: *x⏎➜1⏎
            .b | length➜a: &x [1]⏎b: *x⏎➜0⏎
            .a | length➜a: héllo⏎➜5⏎
            .a | length➜a: ~⏎➜0⏎
            .a | length➜a: {x: 1, y: 2, z: 3}⏎➜3⏎
            .a | length➜a: [1, 2, 3]⏎➜3⏎
            .a | length➜a: 12345⏎➜5⏎
            .a | flatten➜a: [1, [2, [3]]]⏎➜[1, 2, 3]⏎
            .a | flatten(1)➜a: [1, [2, [3]]]⏎➜[1, 2, [3]]⏎
            .a | flatten(0)➜a: [1, [2, [3]]]⏎➜[1, [2, [3]]]⏎
            .a | flatten(empty)➜a: [1, [2, [3]]]⏎➜[1, 2, 3]⏎
            .a | flatten(-1)➜a: [1, [2, [3]]]⏎➜[1, 2, 3]⏎
            .a | flatten➜a:⏎  - 1⏎  - - 2⏎    - - 3⏎➜- 1⏎- 2⏎- 3⏎
            .a | add➜a: [1, 2, 3]⏎➜6⏎
            .a | add➜a: {x: 1, y: 2}⏎➜3⏎
            .a | add➜a: ~⏎➜
            .a | add➜a: []⏎➜
            .a | add(.[])➜a: [1, 2]⏎➜3⏎
            add(.a, .b)➜a: 1⏎b: 2⏎➜3⏎
            .a | add➜a: [x, y]⏎➜xy⏎
            .a | pivot➜a: [[1, 2], [3, 4], [5]]⏎➜- - 1⏎  - 3⏎  - 5⏎- - 2⏎  - 4⏎  -⏎
            .a | pivot➜a: [{x: 1, y: 2}, {y: 3, z: 4}]⏎➜x:⏎  - 1⏎  -⏎y:⏎  - 2⏎  - 3⏎z:⏎  -⏎  - 4⏎
            .a | pivot➜a: []⏎➜[]⏎
            .a | pivot➜a: [[]]⏎➜[]⏎
            .a | pivot➜a: [{}, {x: 1}]⏎➜x:⏎  -⏎  - 1⏎
            .a | pivot➜a: [[1], {x: 1}]⏎➜- - 1⏎  - x⏎- -⏎  - 1⏎
            .a | pivot➜a: [[1,2]]⏎➜- - 1⏎- - 2⏎
            .a | array_to_map➜a: [x, ~, z]⏎➜0: x⏎2: z⏎
            .a | keys➜a: {x: 1, y: 2}⏎➜- x⏎- y⏎
            .a | keys➜a: [x, y]⏎➜- 0⏎- 1⏎
            .a | keys➜a: {x: 1} # c⏎➜- x⏎
            [range(3)]➜a: 1⏎➜- 0⏎- 1⏎- 2⏎
            [range(1;4)]➜a: 1⏎➜- 1⏎- 2⏎- 3⏎
            [range(0;10;3)]➜a: 1⏎➜- 0⏎- 3⏎- 6⏎- 9⏎
            [range(5;0;-2)]➜a: 1⏎➜- 5⏎- 3⏎- 1⏎
            [range(0;3;0)]➜a: 1⏎➜[]⏎
            [range(2;empty)]➜a: 1⏎➜[]⏎
            [range(0;3;empty)]➜a: 1⏎➜- 0⏎- 1⏎- 2⏎
            [range(3;0)]➜a: 1⏎➜[]⏎
            [range(3;3)]➜a: 1⏎➜[]⏎
            [range(5;3;-1)]➜a: 1⏎➜- 5⏎- 4⏎
            [range(0;3;2)]➜a: 1⏎➜- 0⏎- 2⏎
            [range(-1)]➜a: 1⏎➜[]⏎
            [range(0;6;5)]➜a: 1⏎➜- 0⏎- 5⏎
            [range(0;-3;-1)]➜a: 1⏎➜- 0⏎- -1⏎- -2⏎
            TABLE);
    }

    #[DataProvider('failureProvider')]
    public function testRejectsTheInput(string $expression, string $input, string $message): void
    {
        try {
            YqHarness::run($expression, $input);
            self::fail('the call does not apply');
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
            .a | to_entries➜a: 5⏎➜Cannot get entries of !!int
            .a | with_entries(.)➜a: 5⏎➜Cannot get entries of !!int
            with_entries➜a: 1⏎➜with_entries requires 1 argument
            map➜a: 1⏎➜map requires 1 argument
            map_values➜a: 1⏎➜map_values requires 1 argument
            range➜a: 1⏎➜range requires 1 argument
            [range(1.5)]➜a: 1⏎➜strconv.ParseInt: parsing "1.5": invalid syntax
            .a | from_entries➜a: {x: 1}⏎➜Cannot convert !!map from entries
            .a | from_entries➜a: 5⏎➜Cannot convert !!int from entries
            .a | flatten➜a: 5⏎➜Cannot flatten !!int
            .a | flatten➜a: {x: 1}⏎➜Cannot flatten !!map
            .a | add➜a: 5⏎➜Cannot add up !!int
            .a | pivot➜a: 5⏎➜Cannot pivot !!int
            .a | array_to_map➜a: 5⏎➜Cannot convert !!int to a map
            .a | keys➜a: 5⏎➜Cannot get keys of !!int, keys only works on maps and arrays
            .a | keys➜a: ~⏎➜Cannot get keys of !!null, keys only works on maps and arrays
            .a | keys➜a: !!custom 5⏎➜Cannot get keys of !!custom, keys only works on maps and arrays
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
