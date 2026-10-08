<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml;

use LTS\PhpXq\Yaml\AliasExpansion;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The alias-expansion budget refuses what only a bomb produces: more than a million nodes written out and either
 * more than a thousand times the tree or more than a hundred million in all. A merge key's repeated sources are
 * counted once, as merging takes each key once.
 *
 * @internal
 */
#[CoversClass(AliasExpansion::class)]
#[Small]
final class AliasExpansionTest extends TestCase
{
    public function testADocumentWithoutAliasesIsNeverExcessive(): void
    {
        $items = [];
        for ($i = 0; $i < 5000; ++$i) {
            $items[] = Node::scalar((string)$i);
        }

        self::assertFalse(AliasExpansion::isExcessive(Node::document(Node::sequence($items))));
    }

    public function testTheClassicAliasBombIsExcessive(): void
    {
        self::assertTrue(AliasExpansion::isExcessive($this->bomb(7, 10)));
        self::assertTrue(AliasExpansion::isExcessive($this->bomb(9, 10)));
    }

    public function testASmallBombBelowTheFloorIsAllowed(): void
    {
        self::assertFalse(AliasExpansion::isExcessive($this->bomb(5, 10)));
    }

    public function testABillionLaughsIsJudgedWithoutExpandingIt(): void
    {
        $started = hrtime(true);

        self::assertTrue(AliasExpansion::isExcessive($this->bomb(30, 10)));
        self::assertLessThan(1_000_000_000, hrtime(true) - $started);
    }

    public function testAFewAliasesOfASmallAnchorAreAllowed(): void
    {
        $yaml = "base: &b {a: 1, b: 2, c: 3}\n";
        for ($i = 0; $i < 50; ++$i) {
            $yaml .= \sprintf("k%d: *b\n", $i);
        }

        self::assertFalse(AliasExpansion::isExcessive($this->parse($yaml)));
    }

    public function testHeavyReuseIsAllowedWhileTheAliasedShareStaysUnderTheRatio(): void
    {
        $yaml = "base: &b [1, 2, 3, 4]\nplain:\n";
        for ($i = 0; $i < 2000; ++$i) {
            $yaml .= \sprintf("  - %d\n", $i);
        }

        $yaml .= "uses:\n";
        for ($i = 0; $i < 300; ++$i) {
            $yaml .= "  - *b\n";
        }

        self::assertFalse(AliasExpansion::isExcessive($this->parse($yaml)));
    }

    public function testAWideAnchorReusedHundredsOfTimesIsAllowed(): void
    {
        $yaml = 'base: &b [' . implode(', ', range(1, 200)) . "]\nuses:\n";
        for ($i = 0; $i < 600; ++$i) {
            $yaml .= "  - *b\n";
        }

        self::assertFalse(AliasExpansion::isExcessive($this->parse($yaml)));
    }

    /**
     * Thousands of list items each merging a shared base, as configuration templates do: a few hundred times the
     * size of the tree, far below what a bomb reaches.
     */
    #[DataProvider('templates')]
    public function testTemplatesMergingASharedBaseAreAllowed(int $items, int $baseKeys): void
    {
        self::assertFalse(AliasExpansion::isExcessive($this->parse($this->template($items, $baseKeys))));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function templates(): iterable
    {
        yield '3000 items, 100-key base' => [3000, 100];

        yield '1000 items, 500-key base' => [1000, 500];
    }

    public function testALongChainOfAnchorsEachMergingTheLastIsAllowed(): void
    {
        $yaml = "a0: &a0 {k0: 0}\n";
        for ($i = 1; $i < 300; ++$i) {
            $yaml .= \sprintf("a%d: &a%d {<<: *a%d, k%d: %d}\n", $i, $i, $i - 1, $i, $i);
        }

        self::assertFalse(AliasExpansion::isExcessive($this->parse($yaml)));
    }

    public function testALongChainOfAnchorsEachHoldingTheLastIsAllowed(): void
    {
        $yaml = "a0: &a0 [0]\n";
        for ($i = 1; $i < 300; ++$i) {
            $yaml .= \sprintf("a%d: &a%d [*a%d, %d]\n", $i, $i, $i - 1, $i);
        }

        self::assertFalse(AliasExpansion::isExcessive($this->parse($yaml)));
    }

    public function testRepeatedMergeSourcesAreCountedOnce(): void
    {
        $yaml = "l0: &l0 {x: 1}\n";
        for ($level = 1; $level < 12; ++$level) {
            $yaml .= \sprintf("l%d: &l%d {<<: [%s]}\n", $level, $level, implode(', ', array_fill(0, 10, '*l' . ($level - 1))));
        }

        self::assertFalse(AliasExpansion::isExcessive($this->parse($yaml)));
    }

    public function testExpansionPastTheAbsoluteCapIsExcessiveWhateverTheTreeSize(): void
    {
        $scalar = Node::scalar('x');
        $base   = Node::sequence(array_fill(0, 50_000, $scalar));
        $uses   = [];
        for ($i = 0; $i < 2_100; ++$i) {
            $uses[] = Node::alias('b', $base);
        }

        $root = Node::mapping([
            Node::scalar('base'), $base,
            Node::scalar('plain'), Node::sequence(array_fill(0, 150_000, $scalar)),
            Node::scalar('uses'), Node::sequence($uses),
        ]);

        self::assertTrue(AliasExpansion::isExcessive(Node::document($root)));
    }

    public function testACyclicAliasEnds(): void
    {
        $sequence            = Node::sequence();
        $sequence->anchor    = 'a';
        $sequence->content[] = Node::alias('a', $sequence);

        self::assertFalse(AliasExpansion::isExcessive(Node::document($sequence)));
    }

    public function testTheDeepestAcceptedDocumentIsCounted(): void
    {
        $node = Node::scalar('leaf');
        for ($i = 0; $i < Node::MAX_DEPTH; ++$i) {
            $node = Node::sequence([$node]);
        }

        self::assertFalse(AliasExpansion::isExcessive($node));
    }

    /**
     * `a0: &a0 [lol]`, then each level a sequence of `$width` aliases of the level before.
     */
    private function bomb(int $levels, int $width): Node
    {
        $previous = Node::sequence([Node::scalar('lol')]);
        $content  = [Node::scalar('a0'), $previous];
        for ($level = 1; $level < $levels; ++$level) {
            $items = [];
            for ($i = 0; $i < $width; ++$i) {
                $items[] = Node::alias('a' . ($level - 1), $previous);
            }

            $previous  = Node::sequence($items);
            $content[] = Node::scalar('a' . $level);
            $content[] = $previous;
        }

        return Node::document(Node::mapping($content));
    }

    /**
     * A `$baseKeys`-key anchored base and `$items` list items of `{<<: *base, name: nN}`.
     */
    private function template(int $items, int $baseKeys): string
    {
        $yaml = "base: &base\n";
        for ($key = 0; $key < $baseKeys; ++$key) {
            $yaml .= \sprintf("  key%d: value%d\n", $key, $key);
        }

        $yaml .= "items:\n";
        for ($item = 0; $item < $items; ++$item) {
            $yaml .= \sprintf("  - {<<: *base, name: n%d}\n", $item);
        }

        return $yaml;
    }

    private function parse(string $yaml): Node
    {
        $documents = iterator_to_array(new YamlParser()->parse($yaml), false);
        self::assertCount(1, $documents);

        return $documents[0];
    }
}
