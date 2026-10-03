<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\PathTrie;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathTrie::class)]
final class PathTrieTest extends TestCase
{
    public function testGroupsPathsByTheirFirstKey(): void
    {
        $trie = PathTrie::build([['a', 'x'], ['a', 'y'], ['b']]);

        self::assertNotNull($trie);
        self::assertCount(2, $trie->children);
        self::assertFalse($trie->hasIntKeys());
        [$key, $child] = $trie->children['sa'];
        self::assertSame('a', $key);
        self::assertInstanceOf(PathTrie::class, $child);
        self::assertCount(2, $child->children);
        self::assertSame(['b', 2], $trie->children['sb']);
    }

    public function testIntegerKeys(): void
    {
        $trie = PathTrie::build([[0], [1]]);

        self::assertNotNull($trie);
        self::assertTrue($trie->hasIntKeys());
        self::assertSame([0, 0], $trie->children['i0']);
    }

    /**
     * @param list<list<mixed>> $paths
     */
    #[DataProvider('unsupported')]
    public function testRefusesPathSetsThatAreNotIndependent(array $paths): void
    {
        self::assertNull(PathTrie::build($paths));
    }

    /**
     * @return iterable<string, array{list<list<mixed>>}>
     */
    public static function unsupported(): iterable
    {
        yield 'empty path'                 => [[[]]];
        yield 'prefix first'               => [[['a'], ['a', 'b']]];
        yield 'prefix last'                => [[['a', 'b'], ['a']]];
        yield 'duplicate'                  => [[['a'], ['a']]];
        yield 'negative index'             => [[[-1]]];
        yield 'float index'                => [[[1.5]]];
        yield 'slice key'                  => [[[new JsonObject(['start' => 1, 'end' => 2])]]];
        yield 'null key'                   => [[[null]]];
        yield 'mixed keys on one level'    => [[['a'], [0]]];
        yield 'mixed keys deeper'          => [[['a', 'x'], ['a', 0]]];
    }
}
