<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use Generator;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Equality, canonical text, ordering and wildcard matching of nodes. Table rows are fields separated by a
 * pipe.
 *
 * @internal
 */
#[CoversClass(Compare::class)]
final class CompareTest extends TestCase
{
    private const string STR = '!!str';

    private const string MAX_DEPTH_MESSAGE = 'Comparison exceeded max depth (alias cycle?)';

    private const string CANONICAL_DEPTH_MESSAGE = 'Canonical form exceeded max depth (alias cycle?)';

    private const int LIMIT = 10000;

    private const string LEAF = 'leaf';

    private const string FLOAT_ONE = '1.0';

    private const string ABC = 'abc';

    private const string WILDCARD = 'a*';

    #[DataProvider('globProvider')]
    public function testGlob(string $subject, string $pattern, string $matches): void
    {
        self::assertSame('1' === $matches, Compare::glob($subject, $pattern));
    }

    /**
     * @return Generator<string, list<string>> subject, pattern, 1 when it matches
     */
    public static function globProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            abc|abc|1
            abc|abd|0
            abc|a*|1
            abc|*c|1
            abc|*|1
            abc|a*c|1
            abc|*b*|1
            abc|a*b*c|1
            ac|a*c|1
            abc|a*d|0
            abc|b*|0
            abc|*a|0
            |*|1
            |a*|0
            abc|abc*|1
            abcd|abc|0
            a.c|a.c|1
            abc|a.c|0
            abc|a.*|0
            a.cd|a.*|1
            abcd|a.c*|0
            a+b|a+*|1
            aab|a+*|0
            a(b|a(*|1
            ab|a(*|0
            a[b]|a[*|1
            ab|a[*|0
            a^b|a^*|1
            ab$|*$|1
            ab|*$|0
            é|*|1
            éa|é*|1
            aé|*é|1
            éa|a*|0
            TABLE);
    }

    public function testGlobWildcardsCrossLines(): void
    {
        self::assertTrue(Compare::glob("a\nb", 'a*'));
        self::assertTrue(Compare::glob("a\nb\nc", 'a*c'));
        self::assertFalse(Compare::glob("a\nb", 'b*'));
        self::assertFalse(Compare::glob("a\n", 'a'));
        self::assertTrue(Compare::glob('a', 'a'));
    }

    public function testGlobSurvivesCacheRollover(): void
    {
        for ($i = 0; $i < 600; ++$i) {
            self::assertTrue(Compare::glob('p' . $i . 'x', 'p' . $i . '*'));
            self::assertFalse(Compare::glob('q' . $i . 'x', 'p' . $i . '*'));
        }

        self::assertTrue(Compare::glob('p1x', 'p1*'));
        self::assertFalse(Compare::glob('p1x', 'p2*'));
    }

    #[DataProvider('equalsProvider')]
    public function testEqualsOnScalars(string $left, string $right, string $equal): void
    {
        self::assertSame('1' === $equal, Compare::equals(Node::scalar($left), Node::scalar($right)));
    }

    /**
     * @return Generator<string, list<string>> left, right, 1 when equal; tags are resolved from the text
     */
    public static function equalsProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            1|1|1
            1|2|0
            1|1.0|1
            1.0|1|1
            1|0x1|1
            16|0x10|1
            0.5|.5|1
            1|1.5|0
            -1|1|0
            1|a|0
            a|1|0
            1|1*|1
            12|1*|1
            12|2*|0
            a|a|1
            a|b|0
            abc|a*|1
            a*|abc|0
            abc|*|1
            null|null|1
            null|~|1
            ~|null|1
            null|1|0
            1|null|0
            null|a|0
            a|null|0
            true|true|1
            true|false|0
            true|a|0
            TABLE);
    }

    public function testNullTaggedTextIsNotAString(): void
    {
        $null   = Node::scalar('null', CoreSchema::TAG_NULL);
        $tilde  = Node::scalar('~', CoreSchema::TAG_NULL);
        $string = $this->str('null');

        self::assertFalse(Compare::equals($null, $string));
        self::assertFalse(Compare::equals($string, $null));
        self::assertTrue(Compare::equals($null, $tilde));
        self::assertFalse(Compare::deepEquals($null, $string));
        self::assertFalse(Compare::deepEquals($string, $null));
        self::assertTrue(Compare::deepEquals($null, $tilde));
    }

    public function testNumbersCompareByValueNotText(): void
    {
        $one       = Node::scalar('1');
        $floatOne  = Node::scalar(self::FLOAT_ONE);
        $stringOne = $this->str(self::FLOAT_ONE);

        self::assertTrue(Compare::equals($one, $floatOne));
        self::assertTrue(Compare::deepEquals($one, $floatOne));
        self::assertFalse(Compare::deepEquals($one, Node::scalar('2')));
        self::assertTrue(Compare::deepEquals($this->str('1'), $one));
        self::assertFalse(Compare::deepEquals($this->str('1'), $floatOne));
        self::assertTrue(Compare::deepEquals($stringOne, $stringOne));
        self::assertFalse(Compare::deepEquals($this->str('a'), $this->str('b')));
        self::assertTrue(Compare::deepEquals($this->str('a'), $this->str('a')));
    }

    public function testWildcardsOnlyApplyToTheRightHandScalar(): void
    {
        self::assertTrue(Compare::equals($this->str(self::ABC), $this->str(self::WILDCARD)));
        self::assertFalse(Compare::deepEquals($this->str(self::ABC), $this->str(self::WILDCARD)));
    }

    public function testCollectionsAreNeverEqualToScalars(): void
    {
        $sequence = Node::sequence([Node::scalar('1')]);

        self::assertFalse(Compare::equals($sequence, Node::scalar('1')));
        self::assertFalse(Compare::equals(Node::scalar('1'), $sequence));
        self::assertFalse(Compare::equals($sequence, Node::mapping()));
        self::assertFalse(Compare::deepEquals($sequence, Node::mapping()));
        self::assertTrue(Compare::equals($sequence, Node::sequence([Node::scalar('1')])));
        self::assertTrue(Compare::equals(Node::sequence(), Node::sequence()));
        self::assertTrue(Compare::equals(Node::mapping(), Node::mapping()));
    }

    public function testSequencesCompareItemByItem(): void
    {
        $base = Node::sequence([Node::scalar('1'), Node::scalar('2'), Node::scalar('3')]);

        self::assertTrue(Compare::deepEquals($base, Node::sequence([Node::scalar('1'), Node::scalar('2'), Node::scalar('3')])));
        self::assertFalse(Compare::deepEquals($base, Node::sequence([Node::scalar('1'), Node::scalar('2')])));
        self::assertFalse(Compare::deepEquals($base, Node::sequence([Node::scalar('1'), Node::scalar('2'), Node::scalar('3'), Node::scalar('4')])));
        self::assertFalse(Compare::deepEquals($base, Node::sequence([Node::scalar('1'), Node::scalar('9'), Node::scalar('3')])));
        self::assertFalse(Compare::deepEquals($base, Node::sequence([Node::scalar('1'), Node::scalar('2'), Node::scalar('9')])));
        self::assertFalse(Compare::deepEquals($base, Node::sequence([Node::scalar('9'), Node::scalar('2'), Node::scalar('3')])));
        self::assertFalse(Compare::deepEquals($base, Node::sequence([Node::scalar('3'), Node::scalar('2'), Node::scalar('1')])));
    }

    public function testMappingsIgnoreKeyOrderButNotKeysOrValues(): void
    {
        $base = Node::mapping([Node::scalar('a'), Node::scalar('1'), Node::scalar('b'), Node::scalar('2')]);

        self::assertTrue(Compare::deepEquals($base, Node::mapping([Node::scalar('b'), Node::scalar('2'), Node::scalar('a'), Node::scalar('1')])));
        self::assertTrue(Compare::equals($base, Node::mapping([Node::scalar('b'), Node::scalar('2'), Node::scalar('a'), Node::scalar('1')])));
        self::assertFalse(Compare::deepEquals($base, Node::mapping([Node::scalar('a'), Node::scalar('1')])));
        self::assertFalse(Compare::deepEquals($base, Node::mapping([Node::scalar('a'), Node::scalar('1'), Node::scalar('b'), Node::scalar('3')])));
        self::assertFalse(Compare::deepEquals($base, Node::mapping([Node::scalar('a'), Node::scalar('9'), Node::scalar('b'), Node::scalar('2')])));
        self::assertFalse(Compare::deepEquals($base, Node::mapping([Node::scalar('a'), Node::scalar('1'), Node::scalar('c'), Node::scalar('2')])));
        self::assertFalse(Compare::deepEquals($base, Node::mapping([Node::scalar('c'), Node::scalar('1'), Node::scalar('b'), Node::scalar('2')])));
    }

    public function testAliasesAreFollowed(): void
    {
        $target = Node::scalar('1');

        self::assertTrue(Compare::deepEquals(Node::alias('x', $target), Node::scalar('1')));
        self::assertTrue(Compare::equals(Node::scalar('1'), Node::alias('x', $target)));
    }

    public function testDeepEqualsStopsPastTheDepthLimit(): void
    {
        self::assertTrue(Compare::deepEquals(Node::scalar('1'), Node::scalar('1'), self::LIMIT));

        $this->assertThrowsWithMessage(self::MAX_DEPTH_MESSAGE, static fn (): bool => Compare::deepEquals(Node::scalar('1'), Node::scalar('1'), self::LIMIT + 1));
    }

    public function testDeepEqualsCountsSequenceNesting(): void
    {
        self::assertTrue(Compare::deepEquals(self::chain(1), self::chain(1), self::LIMIT - 1));
        self::assertTrue(Compare::deepEquals(self::chain(2), self::chain(2), self::LIMIT - 2));

        $this->assertThrowsWithMessage(self::MAX_DEPTH_MESSAGE, static fn (): bool => Compare::deepEquals(self::chain(3), self::chain(3), self::LIMIT - 2));
    }

    public function testDeepEqualsCountsMappingNesting(): void
    {
        $inner = static fn (): Node => Node::mapping([Node::scalar('k'), Node::mapping([Node::scalar('k'), Node::scalar('v')])]);

        self::assertTrue(Compare::deepEquals($inner(), $inner(), self::LIMIT - 2));
        $this->assertThrowsWithMessage(self::MAX_DEPTH_MESSAGE, static fn (): bool => Compare::deepEquals($inner(), $inner(), self::LIMIT - 1));
    }

    #[DataProvider('canonicalProvider')]
    public function testCanonical(Node $node, string $expected): void
    {
        self::assertSame($expected, Compare::canonical($node));
    }

    /**
     * @return Generator<string, array{Node, string}>
     */
    public static function canonicalProvider(): Generator
    {
        yield 'scalar' => [Node::scalar('plain'), 'plain'];
        yield 'number keeps its text' => [Node::scalar(self::FLOAT_ONE), self::FLOAT_ONE];
        yield 'empty sequence' => [Node::sequence(), '[]'];
        yield 'empty mapping' => [Node::mapping(), '{}'];
        yield 'sequence' => [Node::sequence([Node::scalar('1'), Node::scalar('2')]), "[1\x1f2]"];
        yield 'mapping' => [Node::mapping([Node::scalar('a'), Node::scalar('1')]), "{a\x1e1}"];
        yield 'mapping keys are sorted' => [
            Node::mapping([Node::scalar('b'), Node::scalar('2'), Node::scalar('a'), Node::scalar('1')]),
            "{a\x1e1\x1fb\x1e2}",
        ];
        yield 'nested' => [
            Node::mapping([Node::scalar('k'), Node::sequence([Node::scalar('1'), Node::mapping([Node::scalar('z'), Node::scalar('9')])])]),
            "{k\x1e[1\x1f{z\x1e9}]}",
        ];
        yield 'alias' => [Node::alias('x', Node::scalar('7')), '7'];
    }

    public function testCanonicalStopsPastTheDepthLimit(): void
    {
        self::assertSame(self::LEAF, Compare::canonical(Node::scalar(self::LEAF), self::LIMIT));
        self::assertSame('[' . self::LEAF . ']', Compare::canonical(self::chain(1), self::LIMIT - 1));
        $this->assertThrowsWithMessage(self::CANONICAL_DEPTH_MESSAGE, static fn (): string => Compare::canonical(self::chain(2), self::LIMIT - 1));
    }

    public function testCanonicalCountsMappingNesting(): void
    {
        $mapping = Node::mapping([Node::scalar('k'), Node::scalar('v')]);
        $nested  = Node::mapping([Node::scalar('k'), $mapping]);

        self::assertSame("{k\x1ev}", Compare::canonical($mapping, self::LIMIT - 1));
        self::assertSame("{k\x1e{k\x1ev}}", Compare::canonical($nested, self::LIMIT - 2));
        $this->assertThrowsWithMessage(self::CANONICAL_DEPTH_MESSAGE, static fn (): string => Compare::canonical($nested, self::LIMIT - 1));
    }

    public function testCanonicalWithoutDepthStartsAtZero(): void
    {
        self::assertSame('[' . str_repeat('[', 5) . self::LEAF . str_repeat(']', 5) . ']', Compare::canonical(self::chain(6)));
    }

    #[DataProvider('orderProvider')]
    public function testOrder(string $left, string $right, string $expected): void
    {
        self::assertSame((int)$expected, Compare::order(Node::scalar($left), Node::scalar($right)));
    }

    /**
     * @return Generator<string, list<string>> left, right, expected order
     */
    public static function orderProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            null|null|0
            null|false|-1
            false|null|1
            null|1|-1
            1|null|1
            null|a|-1
            a|null|1
            false|true|-1
            true|false|1
            true|true|0
            false|false|0
            true|1|-1
            1|true|1
            false|-5|-1
            1|2|-1
            2|1|1
            1|1|0
            1|1.0|0
            -1|1|-1
            1.5|1|1
            .nan|1|1
            1|.nan|-1
            .nan|.nan|0
            .nan|-1|1
            -1|.nan|-1
            1|a|-1
            a|1|1
            a|b|-1
            b|a|1
            a|a|0
            a|c|-1
            c|a|1
            abc|abd|-1
            ab|abc|-1
            abc|ab|1
            B|a|-1
            a|B|1
            TABLE);
    }

    public function testCollectionsSortAfterEverythingElse(): void
    {
        $sequence = Node::sequence([Node::scalar('1')]);
        $mapping  = Node::mapping();

        self::assertSame(1, Compare::order($sequence, $this->str('zzz')));
        self::assertSame(-1, Compare::order($this->str('zzz'), $sequence));
        self::assertSame(1, Compare::order($mapping, Node::scalar('1')));
        self::assertSame(0, Compare::order($sequence, $mapping));
        self::assertSame(0, Compare::order($mapping, $mapping));
        self::assertSame(0, Compare::order($sequence, Node::sequence([Node::scalar('2')])));
    }

    public function testNumbersThatDoNotParseSortAsZero(): void
    {
        $broken = Node::scalar('abc', '!!int');

        self::assertSame(-1, Compare::order($broken, Node::scalar('1')));
        self::assertSame(1, Compare::order(Node::scalar('1'), $broken));
        self::assertSame(1, Compare::order($broken, Node::scalar('-1')));
        self::assertSame(-1, Compare::order(Node::scalar('-1'), $broken));
        self::assertSame(0, Compare::order($broken, Node::scalar('0')));
        self::assertSame(0, Compare::order(Node::scalar('0'), $broken));
    }

    public function testAliasesAreFollowedWhenOrdering(): void
    {
        self::assertSame(-1, Compare::order(Node::alias('x', Node::scalar('1')), Node::scalar('2')));
    }

    #[DataProvider('dateOrderProvider')]
    public function testStringsThatAreDatesOrderChronologically(string $left, string $right, string $layout, string $expected): void
    {
        self::assertSame(
            (int)$expected,
            Compare::order($this->str($left), $this->str($right), '~' === $layout ? null : $layout),
        );
    }

    /**
     * @return Generator<string, list<string>> left, right, layout (tilde for none), expected order
     */
    public static function dateOrderProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            2001-01-01T10:00:00Z|2001-01-01T11:00:00+02:00|~|1
            2001-01-01T11:00:00+02:00|2001-01-01T10:00:00Z|~|-1
            2001-01-01T10:00:00Z|2001-01-01T12:00:00+02:00|~|0
            0999-01-01T10:00:00Z|0999-01-01T11:00:00+02:00|~|1
            9999-01-01T10:00:00Z|9999-01-01T11:00:00+02:00|~|1
            2001-01-01|2001-01-02|~|-1
            2001-01-02|2001-01-01|~|1
            2001-01-01T10:00:00Z|2001-zz|~|-1
            2001-zz|2001-01-01T10:00:00Z|~|1
            2001-01-01T10:00:00Z|abc|~|-1
            abc|2001-01-01T10:00:00Z|~|1
            2001-01-01T10:00:00Z||~|1
            |2001-01-01T10:00:00Z|~|-1
            |a|~|-1
            a||~|1
            1abc|2abc|~|-1
            :00|;00|~|-1
            05/01/2001|04/02/2001|02/01/2006|-1
            04/02/2001|05/01/2001|02/01/2006|1
            05/01/2001|05/01/2001|02/01/2006|0
            05/01/2001|zz|02/01/2006|-1
            zz|05/01/2001|02/01/2006|1
            TABLE);
    }

    /**
     * @return Generator<string, list<string>>
     */
    private static function rows(string $table): Generator
    {
        foreach (explode("\n", trim($table)) as $line) {
            yield $line => explode('|', $line);
        }
    }

    private function str(string $value): Node
    {
        return Node::scalar($value, self::STR);
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertThrowsWithMessage(string $message, callable $call): void
    {
        try {
            $call();
            self::fail('the call does not throw');
        } catch (EvaluationException $evaluationException) {
            self::assertSame($message, $evaluationException->getMessage());
        }
    }

    /**
     * A chain of single-item sequences that many levels deep.
     */
    private static function chain(int $levels): Node
    {
        $node = Node::scalar(self::LEAF);
        for ($i = 0; $i < $levels; ++$i) {
            $node = Node::sequence([$node]);
        }

        return $node;
    }
}
