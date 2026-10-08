<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yaml\AliasExpansion;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Runtime\Traversal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A chain of mappings that each merge the one before many times over (`l1: {<<: [*l0, *l0, ...]}`, level upon
 * level) has few distinct keys but exponentially many merge paths. Each mapping's merged entries are worked out
 * once per lookup, so navigation stays linear in the document, and the alias-expansion budget counts a repeated
 * merge source once, so the encoders write it.
 *
 * @internal
 */
#[CoversClass(Traversal::class)]
#[CoversClass(AliasExpansion::class)]
#[CoversClass(NodeTools::class)]
#[Medium]
final class MergeKeyFanOutTest extends TestCase
{
    /** Far below the seconds the fan-out took when every merge path was walked, far above the linear cost. */
    private const int TIME_LIMIT_NANOSECONDS = 2_000_000_000;

    /** Mappings in the chain: 10^6 merge paths reach the first, which took seconds when each was walked. */
    private const int LEVELS = 7;

    /** Times each mapping merges the one before it. */
    private const int WIDTH = 10;

    #[DataProvider('navigation')]
    public function testNavigationThroughAMergeFanOutIsLinear(string $expected, string ...$arguments): void
    {
        $document = $this->fanOut(self::LEVELS, self::WIDTH);
        $started  = hrtime(true);

        self::assertSame([0, $expected, ''], $this->invoke($document, ...$arguments));
        self::assertLessThan(self::TIME_LIMIT_NANOSECONDS, hrtime(true) - $started);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function navigation(): iterable
    {
        yield 'lookup' => ["1\n", '.l6.x'];
        yield 'values' => ["1\n", '.l6[]'];
        yield 'spec-fixed lookup' => ["1\n", '--yaml-fix-merge-anchor-to-spec', '.l6.x'];
    }

    /**
     * Merging takes each key once, so the alias budget counts a repeated merge source once and the fan-out is
     * written, small as it is, rather than refused.
     */
    public function testAnEncoderWritesAMergeFanOutInLinearTime(): void
    {
        $started = hrtime(true);

        self::assertSame([0, "{\"x\":1}\n", ''], $this->invoke($this->fanOut(self::LEVELS * 2, self::WIDTH), '-o=json', '-I=0', '.l13'));
        self::assertLessThan(self::TIME_LIMIT_NANOSECONDS, hrtime(true) - $started);
    }

    public function testAnEncoderWritesASmallFanOutOnce(): void
    {
        self::assertSame([0, "{\"x\":1}\n", ''], $this->invoke($this->fanOut(3, 3), '-o=json', '-I=0', '.l2'));
    }

    private function fanOut(int $levels, int $width): string
    {
        $yaml = "l0: &l0 {x: 1}\n";
        for ($level = 1; $level < $levels; ++$level) {
            $yaml .= \sprintf("l%d: &l%d {<<: [%s]}\n", $level, $level, implode(', ', array_fill(0, $width, '*l' . ($level - 1))));
        }

        return $yaml;
    }

    /**
     * @return array{int, string, string}
     */
    private function invoke(string $stdin, string ...$arguments): array
    {
        $result = new CliRunner()->run(['yq', ...array_values($arguments)], $stdin);

        return [$result->exitCode, $result->stdout, $result->stderr];
    }
}
