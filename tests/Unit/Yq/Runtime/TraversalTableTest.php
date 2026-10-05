<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use LTS\PhpXq\Yq\Runtime\Traversal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Field access, indexing, key lookup with merge keys and globs, and the recursive descent. A row is expression,
 * input and expected output separated by an arrow; a return sign stands for a newline.
 *
 * @internal
 */
#[CoversClass(Traversal::class)]
final class TraversalTableTest extends TestCase
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
            .a["1x"]?➜a: [1, 2]⏎➜
            .a["*"]➜a: [1, 2]⏎➜
            .a[2]➜a: [1, 2]⏎➜null⏎
            .a[1]➜a: [1, 2]⏎➜2⏎
            .a[-1]➜a: [1, 2]⏎➜2⏎
            .a[-2]➜a: [1, 2]⏎➜1⏎
            .a[3] = 1➜a: [1, 2]⏎➜a: [1, 2, null, 1]⏎
            .a[2] = 1➜a: [1, 2]⏎➜a: [1, 2, 1]⏎
            .a➜x: a⏎a: 1⏎➜1⏎
            .a➜a: 1⏎a: 2⏎➜1⏎2⏎
            .x➜<<: {x: 1}⏎y: 2⏎➜1⏎
            .y➜<<: {x: 1}⏎y: 2⏎➜2⏎
            .["<<"]➜<<: {x: 1}⏎y: 2⏎➜{x: 1}⏎
            .["a*"]➜ab: 1⏎ac: 2⏎b: 3⏎➜1⏎2⏎
            .ab➜ab: 1⏎ac: 2⏎b: 3⏎➜1⏎
            .["z*"] = 1➜a: 1⏎➜a: 1⏎
            .["z*"]➜a: 1⏎➜
            .z = 1➜a: 1⏎➜a: 1⏎z: 1⏎
            [..]➜a: {b: 1}⏎➜- a: {b: 1}⏎- {b: 1}⏎- 1⏎
            [...]➜a: {b: 1}⏎➜- a: {b: 1}⏎- a⏎- {b: 1}⏎- b⏎- 1⏎
            [..]➜<<: {x: 1}⏎y: 2⏎➜- <<: {x: 1}⏎  y: 2⏎- {x: 1}⏎- 1⏎- 2⏎
            [...]➜<<: {x: 1}⏎y: 2⏎➜- <<: {x: 1}⏎  y: 2⏎- <<⏎- {x: 1}⏎- x⏎- 1⏎- y⏎- 2⏎
            [..]➜- 1⏎- [2]⏎➜- - 1⏎  - [2]⏎- 1⏎- [2]⏎- 2⏎
            .a.[0] = 1➜a: ~⏎➜a:⏎  - 1⏎
            .a["0"] = 1➜a: ~⏎➜a:⏎  "0": 1⏎
            .a.b = 1➜a: ~⏎➜a:⏎  b: 1⏎
            .a["*"] = 1➜a: ~⏎➜a: ~⏎
            .b.c➜a: 1⏎➜null⏎
            .a.c➜a: 1⏎➜
            .a.c = 1➜a: 1⏎➜a: 1⏎
            .a.c➜a: ~⏎➜null⏎
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
            .a["1x"]➜a: [1, 2]⏎➜Cannot index array with '1x'
            .a["x1"]➜a: [1, 2]⏎➜Cannot index array with 'x1'
            .a["1\n"]➜a: [1, 2]⏎➜Cannot index array with '1⏎'
            .a[-3]➜a: [1, 2]⏎➜Index [-3] out of range, array size is 2
            .a[-3] = 1➜a: [1, 2]⏎➜Index [-3] out of range, array size is 2
            TABLE);
    }

    #[DataProvider('failureProvider')]
    public function testRejectsTheAccess(string $expression, string $input, string $message): void
    {
        try {
            YqHarness::run($expression, $input);
            self::fail('the access does not apply');
        } catch (EvaluationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    public function testLookupFindsEveryMatchingKeyInOrder(): void
    {
        $map = NodeOps::map([
            NodeOps::str('a'), NodeOps::int(1),
            NodeOps::str('b'), NodeOps::int(2),
            NodeOps::str('a'), NodeOps::int(3),
        ]);

        $found = Traversal::lookup($map, 'a', false, false);

        self::assertSame(['1', '3'], array_map(static fn (array $pair): string => $pair[1]->value, $found));
        self::assertSame([], Traversal::lookup($map, 'c', false, false));
    }

    public function testLookupMatchesGlobsOnKeysOnly(): void
    {
        $map = NodeOps::map([
            NodeOps::str('ab'), NodeOps::str('ac'),
            NodeOps::str('ad'), NodeOps::str('b'),
        ]);

        $found = Traversal::lookup($map, 'a*', true, false);

        self::assertSame(['ac', 'b'], array_map(static fn (array $pair): string => $pair[1]->value, $found));
    }

    public function testLookupThroughAMergeKeyFindsTheMergedEntries(): void
    {
        $map = NodeOps::map([
            NodeOps::str('<<'), NodeOps::map([NodeOps::str('x'), NodeOps::int(1)]),
            NodeOps::str('y'), NodeOps::int(2),
        ]);

        self::assertSame(['1'], array_map(static fn (array $pair): string => $pair[1]->value, Traversal::lookup($map, 'x', false, false)));
        self::assertSame(['2'], array_map(static fn (array $pair): string => $pair[1]->value, Traversal::lookup($map, 'y', false, false)));
        self::assertSame([], Traversal::lookup($map, 'z', false, false));
    }

    public function testLookupOfTheMergeKeyItselfReturnsItsValue(): void
    {
        $value = NodeOps::map([NodeOps::str('x'), NodeOps::int(1)]);
        $map   = NodeOps::map([NodeOps::str('<<'), $value, NodeOps::str('y'), NodeOps::int(2)]);

        $found = Traversal::lookup($map, '<<', false, false);

        self::assertCount(1, $found);
        self::assertSame($value, $found[0][1]);
    }

    public function testMergedEntriesFollowTheMergeOrder(): void
    {
        $merged = NodeOps::map([NodeOps::str('x'), NodeOps::int(2), NodeOps::str('z'), NodeOps::int(3)]);
        $map    = NodeOps::map([NodeOps::str('x'), NodeOps::int(1), NodeOps::str('<<'), $merged]);

        $legacy = Traversal::entries($map, false);
        $fixed  = Traversal::entries($map, true);

        self::assertSame(['x' => '2', 'z' => '3'], array_map(static fn (array $pair): string => $pair[1]->value, $legacy));
        self::assertSame(['x' => '1', 'z' => '3'], array_map(static fn (array $pair): string => $pair[1]->value, $fixed));
        self::assertSame('x', $legacy['x'][0]->value);
    }
}
