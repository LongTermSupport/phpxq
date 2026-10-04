<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\CollectionCalls;
use LTS\PhpXq\Yq\Runtime\Operators\SelectionCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Operators that recurse through a document must stop with an error on a cyclic alias and must cope with a
 * very deep acyclic document.
 *
 * @internal
 */
#[CoversClass(SelectionCalls::class)]
#[CoversClass(CollectionCalls::class)]
#[CoversClass(Compare::class)]
final class CyclicAliasCallsTest extends TestCase
{
    private const string CYCLE = "a: &x\n  b: *x\n  c: [1]\nd: &y [*y, 2]\n";

    #[DataProvider('cyclicExpressions')]
    public function testCyclicAliasStopsWithAnError(string $expression): void
    {
        try {
            YqHarness::run($expression, self::CYCLE);
            self::fail('A cyclic alias must not evaluate to the end.');
        } catch (EvaluationException $exception) {
            self::assertStringContainsString('exceeded max depth', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cyclicExpressions(): iterable
    {
        yield 'sequence equality' => ['.d == .d'];
        yield 'mapping equality' => ['.a == .a'];
        yield 'contains a sequence' => ['.d | contains(.)'];
        yield 'contains a mapping' => ['.a | contains(.)'];
        yield 'unique' => ['.d | unique'];
        yield 'flatten without a depth' => ['.d | flatten'];
        yield 'flatten with a negative depth' => ['.d | flatten(-1)'];
    }

    public function testContainsHandlesVeryDeepNestingWithoutANativeStackError(): void
    {
        $deep = str_repeat('[', 3000) . '1' . str_repeat(']', 3000);

        self::assertSame("true\n", YqHarness::run('contains(.)', $deep . "\n"));
    }

    public function testFlattenWithADepthStillBoundsTheUserDepth(): void
    {
        self::assertSame("[1, [2]]\n", YqHarness::run('flatten(1)', "[[1], [[2]]]\n"));
        self::assertSame("[1, 2]\n", YqHarness::run('flatten', "[[1], [[2]]]\n"));
    }
}
