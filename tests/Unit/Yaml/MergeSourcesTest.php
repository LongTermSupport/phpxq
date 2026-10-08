<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml;

use LTS\PhpXq\Yaml\MergeSources;
use LTS\PhpXq\Yaml\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MergeSources::class)]
#[Small]
final class MergeSourcesTest extends TestCase
{
    public function testAnInlineMappingIsItsOwnSource(): void
    {
        $mapping = Node::mapping([Node::scalar('a'), Node::scalar('1')]);

        self::assertSame([$mapping], MergeSources::of($mapping));
    }

    public function testAnAliasIsResolvedThroughAChain(): void
    {
        $mapping = Node::mapping();

        self::assertSame([$mapping], MergeSources::of(Node::alias('b', Node::alias('a', $mapping))));
    }

    public function testASequenceGivesItsMappingsInOrderAndSkipsEverythingElse(): void
    {
        $first  = Node::mapping();
        $second = Node::mapping();
        $value  = Node::sequence([Node::alias('a', $first), Node::scalar('x'), $second, Node::sequence([Node::mapping()])]);

        self::assertSame([$first, $second], MergeSources::of($value));
    }

    public function testAnAliasOfASequenceGivesTheSequenceMappings(): void
    {
        $mapping = Node::mapping();

        self::assertSame([$mapping], MergeSources::of(Node::alias('s', Node::sequence([$mapping]))));
    }

    public function testAScalarMergesNothing(): void
    {
        self::assertSame([], MergeSources::of(Node::scalar('x')));
        self::assertSame([], MergeSources::of(Node::alias('a', Node::scalar('x'))));
    }

    public function testTheLegacyFormTakesOnlyAliasTargetsWhateverTheirKind(): void
    {
        $mapping = Node::mapping();
        $scalar  = Node::scalar('5');

        self::assertSame([$mapping], MergeSources::aliased(Node::alias('a', Node::alias('b', $mapping))));
        self::assertSame([$mapping, $scalar], MergeSources::aliased(Node::sequence([Node::alias('a', $mapping), Node::mapping(), Node::alias('s', $scalar)])));
        self::assertSame([], MergeSources::aliased(Node::mapping()));
        self::assertSame([], MergeSources::aliased(Node::scalar('x')));
    }

    public function testACyclicAliasChainMergesNothing(): void
    {
        $alias              = Node::alias('a', Node::mapping());
        $alias->aliasTarget = $alias;

        self::assertSame([], MergeSources::of($alias));
    }
}
