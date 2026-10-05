<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Equality and canonical text must stop with an error on a cyclic alias and must not exhaust the native
 * stack on a very deep acyclic document.
 *
 * @internal
 */
#[CoversClass(Compare::class)]
final class CompareCycleTest extends TestCase
{
    public function testDeepEqualsStopsOnACyclicAlias(): void
    {
        $cycle = $this->cycle();

        try {
            Compare::deepEquals($cycle, $cycle);
            self::fail('A cyclic alias must not compare to the end.');
        } catch (EvaluationException $evaluationException) {
            self::assertStringContainsString('exceeded max depth', $evaluationException->getMessage());
        }
    }

    public function testCanonicalStopsOnACyclicAlias(): void
    {
        try {
            Compare::canonical($this->cycle());
            self::fail('A cyclic alias must not render to the end.');
        } catch (EvaluationException $evaluationException) {
            self::assertStringContainsString('exceeded max depth', $evaluationException->getMessage());
        }
    }

    public function testDeepEqualsComparesVeryDeepNestingWithoutANativeStackError(): void
    {
        self::assertTrue(Compare::deepEquals($this->nested(3000), $this->nested(3000)));
        self::assertFalse(Compare::deepEquals($this->nested(3000), $this->nested(2999)));
    }

    public function testNormalDocumentsCompareAsBefore(): void
    {
        $left  = Node::mapping([Node::scalar('a'), Node::sequence([Node::scalar('1'), Node::scalar('x')]), Node::scalar('b'), Node::scalar('2')]);
        $right = Node::mapping([Node::scalar('b'), Node::scalar('2.0'), Node::scalar('a'), Node::sequence([Node::scalar('1'), Node::scalar('x')])]);
        $other = Node::mapping([Node::scalar('b'), Node::scalar('2'), Node::scalar('a'), Node::sequence([Node::scalar('1'), Node::scalar('y')])]);

        self::assertTrue(Compare::deepEquals($left, $right));
        self::assertFalse(Compare::deepEquals($left, $other));
        self::assertSame('{a' . "\x1e" . '[1' . "\x1f" . 'x]' . "\x1f" . 'b' . "\x1e" . '2}', Compare::canonical($left));
    }

    private function cycle(): Node
    {
        $sequence            = Node::sequence([Node::scalar('2')]);
        $sequence->content[] = Node::alias('y', $sequence);

        return $sequence;
    }

    private function nested(int $levels): Node
    {
        $node = Node::scalar('leaf');
        for ($i = 0; $i < $levels; ++$i) {
            $node = Node::sequence([$node]);
        }

        return $node;
    }
}
