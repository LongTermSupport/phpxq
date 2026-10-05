<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\SortingCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * sort, sort_by, group_by, unique, unique_by, min, max, reverse and shuffle over documents. A row is
 * expression, input and expected output separated by an arrow; a return sign stands for a newline.
 *
 * @internal
 */
#[CoversClass(SortingCalls::class)]
final class SortingCallsTableTest extends TestCase
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
            .a | sort➜a: [3, 1, 2]⏎➜[1, 2, 3]⏎
            .a | sort➜a: [1.5, 1, 2]⏎➜[1, 1.5, 2]⏎
            .a | sort➜a: [-1, -3, 2]⏎➜[-3, -1, 2]⏎
            .a | sort➜a: [b, a, c]⏎➜[a, b, c]⏎
            .a | sort➜a: [b, B, a]⏎➜[B, a, b]⏎
            .a | sort➜a: [b, "", a]⏎➜["", a, b]⏎
            .a | sort➜a: [ab, a, abc]⏎➜[a, ab, abc]⏎
            .a | sort➜a: [b, 1, a]⏎➜[1, a, b]⏎
            .a | sort➜a: [1, b]⏎➜[1, b]⏎
            .a | sort➜a: [b, 1]⏎➜[1, b]⏎
            .a | sort➜a: [b, null, 1, true, false]⏎➜[null, false, true, 1, b]⏎
            .a | sort➜a: [.nan, 1]⏎➜[1, .nan]⏎
            .a | sort➜a: [1, .nan]⏎➜[1, .nan]⏎
            .a | sort➜a: [2, .nan, 1]⏎➜[1, 2, .nan]⏎
            .a | sort➜a: ["0999-01-01T10:00:00Z", "0999-01-01T11:00:00+02:00"]⏎➜["0999-01-01T11:00:00+02:00", "0999-01-01T10:00:00Z"]⏎
            .a | sort➜a: ["9999-01-01T10:00:00Z", "9999-01-01T11:00:00+02:00"]⏎➜["9999-01-01T11:00:00+02:00", "9999-01-01T10:00:00Z"]⏎
            .a | sort➜a: ["2001-01-01T10:00:00Z", "2001-01-01T11:00:00+02:00"]⏎➜["2001-01-01T11:00:00+02:00", "2001-01-01T10:00:00Z"]⏎
            .a | sort➜a: ["a", "2001-01-01"]⏎➜["2001-01-01", "a"]⏎
            .a | sort➜a: [[2], [1]]⏎➜[[2], [1]]⏎
            .a | sort➜a: [{x: 1}, 2, [3]]⏎➜[2, {x: 1}, [3]]⏎
            .a | sort➜a: []⏎➜[]⏎
            .a | sort➜a: [1]⏎➜[1]⏎
            .a | sort➜a: {x: 3, y: 1, z: 2}⏎➜{y: 1, z: 2, x: 3}⏎
            .a | sort➜a: null⏎➜null⏎
            .a | sort➜a:⏎  - 3⏎  - 1⏎➜- 1⏎- 3⏎
            .a | sort_by(.k)➜a: [{k: 2}, {k: 1}]⏎➜[{k: 1}, {k: 2}]⏎
            .a | sort_by(.k)➜a: [{k: b}, {k: a}]⏎➜[{k: a}, {k: b}]⏎
            .a | sort_by(.k)➜a: [{k: 1, n: x}, {k: 1, n: y}, {k: 0, n: z}]⏎➜[{k: 0, n: z}, {k: 1, n: x}, {k: 1, n: y}]⏎
            .a | sort_by(.k)➜a: [{k: 2}, {n: 1}, {k: 1}]⏎➜[{n: 1}, {k: 1}, {k: 2}]⏎
            .a | sort_by(.k, .n)➜a: [{k: 1, n: 2}, {k: 1, n: 1}, {k: 0, n: 3}]⏎➜[{k: 0, n: 3}, {k: 1, n: 1}, {k: 1, n: 2}]⏎
            .a | sort_by(.k, .n)➜a: [{k: 1}, {k: 1, n: 1}]⏎➜[{k: 1}, {k: 1, n: 1}]⏎
            .a | sort_by(.k, .n)➜a: [{k: 1, n: 1}, {k: 1}]⏎➜[{k: 1}, {k: 1, n: 1}]⏎
            .a | sort_by(.k, .n)➜a: [{k: 1, n: 5}, {k: 1, n: 4}, {k: 1, n: 4}]⏎➜[{k: 1, n: 4}, {k: 1, n: 4}, {k: 1, n: 5}]⏎
            .a | sort_by(.k)➜a: [{k: [1]}, {k: 2}]⏎➜[{k: 2}, {k: [1]}]⏎
            .a | sort_by(.k)➜a: [{k: a}, {k: 2}]⏎➜[{k: 2}, {k: a}]⏎
            .a | sort_by(.k)➜a: [{k: 2}, {k: .nan}, {k: 1}]⏎➜[{k: 1}, {k: 2}, {k: .nan}]⏎
            .a | sort_by(.k)➜a: [{k: 3}, {k: 1.5}]⏎➜[{k: 1.5}, {k: 3}]⏎
            .a | sort_by(.k)➜a: {x: {k: 2}, y: {k: 1}}⏎➜{y: {k: 1}, x: {k: 2}}⏎
            .a | sort_by(.k)➜a: null⏎➜null⏎
            .a | sort_by(.k)➜a: []⏎➜[]⏎
            .a | sort_by(.k)➜a: [{k: 1}]⏎➜[{k: 1}]⏎
            .a | sort_by(.[0])➜a: [[2, x], [1, y]]⏎➜[[1, y], [2, x]]⏎
            .a | group_by(.t)➜a: [{t: a, n: 1}, {t: b, n: 2}, {t: a, n: 3}]⏎➜- - {t: a, n: 1}⏎  - {t: a, n: 3}⏎- - {t: b, n: 2}⏎
            .a | group_by(.t)➜a: [{t: 1}, {t: 2}, {t: 1}]⏎➜- - {t: 1}⏎  - {t: 1}⏎- - {t: 2}⏎
            .a | group_by(.t)➜a: [{n: 1}, {t: a}, {n: 2}]⏎➜- - {n: 1}⏎  - {n: 2}⏎- - {t: a}⏎
            .a | group_by(.t)➜a: [{t: [1]}, {t: x}, {t: {k: 1}}]⏎➜- - {t: [1]}⏎  - {t: {k: 1}}⏎- - {t: x}⏎
            .a | group_by(.t)➜a: []⏎➜[]⏎
            .a | group_by(.t)➜a: null⏎➜null⏎
            .a | group_by(.t)➜a: [{t: b}, {t: a}]⏎➜- - {t: b}⏎- - {t: a}⏎
            .a | unique➜a: [1, 2, 1]⏎➜- 1⏎- 2⏎
            .a | unique➜a: [b, a, b, c]⏎➜- b⏎- a⏎- c⏎
            .a | unique➜a: [1, "1"]⏎➜- 1⏎
            .a | unique➜a: [{a: 1}, {a: 1}, {a: 2}]⏎➜- {a: 1}⏎- {a: 2}⏎
            .a | unique➜a: [{a: 1, b: 2}, {b: 2, a: 1}]⏎➜- {a: 1, b: 2}⏎
            .a | unique➜a: [[1], [1], [2]]⏎➜- [1]⏎- [2]⏎
            .a | unique➜a: [null, null, 1]⏎➜- null⏎- 1⏎
            .a | unique➜a: []⏎➜[]⏎
            .a | unique➜a: null⏎➜null⏎
            .a | unique_by(.k)➜a: [{k: 1, n: x}, {k: 1, n: y}, {k: 2, n: z}]⏎➜- {k: 1, n: x}⏎- {k: 2, n: z}⏎
            .a | unique_by(.k)➜a: [{n: 1}, {n: 2}, {k: 1}]⏎➜- {n: 1}⏎- {k: 1}⏎
            .a | unique_by(.k)➜a: [{k: 1}, {k: "1"}]⏎➜- {k: 1}⏎
            .a | unique_by(.k)➜a: [{k: [1]}, {k: [1]}, {k: [2]}]⏎➜- {k: [1]}⏎- {k: [2]}⏎
            .a | min➜a: [3, 1, 2]⏎➜1⏎
            .a | max➜a: [3, 1, 2]⏎➜3⏎
            .a | min➜a: [b, a, c]⏎➜a⏎
            .a | max➜a: [b, a, c]⏎➜c⏎
            .a | min➜a: [2, 1, 1.0]⏎➜1⏎
            .a | max➜a: [2, 3, 3.0]⏎➜3⏎
            .a | max➜a: [1, 3.0, 3]⏎➜3.0⏎
            .a | min➜a: [1.0, 1]⏎➜1.0⏎
            .a | min➜a: [{id: 1}, {id: 2}]⏎➜{id: 1}⏎
            .a | max➜a: [{id: 1}, {id: 2}]⏎➜{id: 1}⏎
            .a | min➜a: [1]⏎➜1⏎
            .a | min➜a: []⏎➜
            .a | max➜a: []⏎➜
            .a | min➜a: null⏎➜
            .a | max➜a: null⏎➜
            .a | min➜a: {x: 3, y: 1}⏎➜1⏎
            .a | max➜a: {x: 3, y: 1}⏎➜3⏎
            .a | min➜a: ["2001-01-01T10:00:00Z", "2001-01-01T11:00:00+02:00"]⏎➜2001-01-01T11:00:00+02:00⏎
            .a | max➜a: ["2001-01-01T10:00:00Z", "2001-01-01T11:00:00+02:00"]⏎➜2001-01-01T10:00:00Z⏎
            .a | reverse➜a: [1, 2, 3]⏎➜[3, 2, 1]⏎
            .a | reverse➜a:⏎  - 1⏎  - 2⏎➜- 2⏎- 1⏎
            .a | reverse➜a: []⏎➜[]⏎
            .a | reverse➜a: {x: 1, y: 2}⏎➜{x: 1, y: 2}⏎
            .a | reverse➜a: null⏎➜null⏎
            .a | reverse➜a: [{k: 1}, {k: 2}]⏎➜[{k: 2}, {k: 1}]⏎
            .a | shuffle➜a: []⏎➜[]⏎
            .a | shuffle➜a: [1]⏎➜- 1⏎
            .a | shuffle➜a: null⏎➜null⏎
            ., sort_by(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎- {a: 1}⏎
            ., group_by(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎- - {a: 1}⏎
            ., unique_by(.k.m)➜- {a: 1}⏎➜- {a: 1}⏎- {a: 1}⏎
            TABLE);
    }

    public function testShuffleReordersSequences(): void
    {
        $items    = range(1, 30);
        $input    = 'a: [' . implode(', ', $items) . "]\n";
        $shuffled = YqHarness::run('.a | shuffle | .[]', $input);
        $values   = array_map(intval(...), explode("\n", trim($shuffled)));

        self::assertNotSame($items, $values, 'thirty items come back in a new order');
        sort($values);
        self::assertSame($items, $values, 'nothing is lost or duplicated');
    }

    public function testShuffleTakesTheValuesOfMappings(): void
    {
        $values = array_map(intval(...), explode("\n", trim(YqHarness::run('.a | shuffle | .[]', "a: {x: 1, y: 2, z: 3}\n"))));
        sort($values);

        self::assertSame([1, 2, 3], $values);
    }

    #[DataProvider('failureProvider')]
    public function testRejectsScalars(string $expression, string $input, string $message): void
    {
        try {
            YqHarness::run($expression, $input);
            self::fail('scalars cannot be ordered');
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
            .a | sort➜a: 5⏎➜Cannot sort !!int
            .a | sort_by(.k)➜a: x⏎➜Cannot sort_by !!str
            .a | group_by(.k)➜a: true⏎➜Cannot group_by !!bool
            .a | unique➜a: 1.5⏎➜Cannot unique !!float
            .a | unique_by(.k)➜a: x⏎➜Cannot unique_by !!str
            .a | reverse➜a: 5⏎➜Cannot reverse !!int
            .a | shuffle➜a: 5⏎➜Cannot shuffle !!int
            TABLE);
    }

    #[DataProvider('missingArgumentProvider')]
    public function testCallsThatNeedAKeyRejectNone(string $expression): void
    {
        $this->expectException(EvaluationException::class);

        YqHarness::run('.a | ' . $expression, "a: [1]\n");
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function missingArgumentProvider(): Generator
    {
        foreach (['sort_by', 'group_by', 'unique_by'] as $name) {
            yield $name => [$name . '()'];
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
