<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml;

use LTS\PhpXq\Yaml\AliasExpansion;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The alias-expansion budget follows go-yaml's "excessive aliasing" check: a document fails when more than 100
 * nodes come out of aliases, more than 1000 nodes come out in all, and the aliased share is above the allowed
 * ratio (0.99 up to 400,000 nodes, falling to 0.10 at 4,000,000).
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
        self::assertTrue(AliasExpansion::isExcessive($this->bomb(5, 10)));
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

    public function testExpansionPastTheRatioIsExcessiveEvenForAWideAnchor(): void
    {
        $yaml = 'base: &b [' . implode(', ', range(1, 200)) . "]\nuses:\n";
        for ($i = 0; $i < 600; ++$i) {
            $yaml .= "  - *b\n";
        }

        self::assertTrue(AliasExpansion::isExcessive($this->parse($yaml)));
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

    private function parse(string $yaml): Node
    {
        $documents = iterator_to_array(new YamlParser()->parse($yaml), false);
        self::assertCount(1, $documents);

        return $documents[0];
    }
}
