<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Limits\NestingLimit;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Cli\YqApplication;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A long chain is not nesting, so it runs as Go yq runs it up to {@see NestingLimit::MAX_TREE_DEPTH} links;
 * real nesting past the limit is a syntax error. The
 * evaluation runs on a large fiber stack, so a chain as deep as it is long cannot overflow the process stack
 * under a coverage driver.
 *
 * @internal
 */
#[CoversClass(YqApplication::class)]
#[Medium]
final class YqApplicationNestingTest extends TestCase
{
    private const int LONG_CHAIN = 30000;

    public function testLongChainsRun(): void
    {
        $union  = new CliRunner()->run(['yq', '-n', '[' . implode(', ', array_fill(0, self::LONG_CHAIN, '0')) . '] | length']);
        $sum    = new CliRunner()->run(['yq', '-n', $this->sum(self::LONG_CHAIN)]);
        $fields = new CliRunner()->run(['yq', '-n', str_repeat('.a', self::LONG_CHAIN)]);
        $pipes  = new CliRunner()->run(['yq', '-n', '1' . str_repeat(' | . + 1', self::LONG_CHAIN)]);

        self::assertSame(['', self::LONG_CHAIN . "\n"], [$union->stderr, $union->stdout]);
        self::assertSame(['', self::LONG_CHAIN . "\n"], [$sum->stderr, $sum->stdout]);
        self::assertSame(['', "null\n"], [$fields->stderr, $fields->stdout]);
        self::assertSame(['', (self::LONG_CHAIN + 1) . "\n"], [$pipes->stderr, $pipes->stdout]);
    }

    /**
     * The longest chain the tree limit accepts runs and its expression is freed; one link more is a syntax error.
     */
    public function testAChainAtTheTreeLimitRunsAndOnePastItIsASyntaxError(): void
    {
        // the first term sits under MAX_TREE_DEPTH additions
        $terms   = NestingLimit::MAX_TREE_DEPTH + 1;
        $atLimit = new CliRunner()->run(['yq', '-n', $this->sum($terms)]);
        $past    = new CliRunner()->run(['yq', '-n', $this->sum($terms + 1)]);

        self::assertSame(['', $terms . "\n"], [$atLimit->stderr, $atLimit->stdout]);
        self::assertSame(
            "Error: Bad expression, tree deeper than 100000 levels, counting chained operations\n  at offset 400005 of the expression\n",
            $past->stderr,
        );
        self::assertSame('', $past->stdout);
    }

    public function testNestingPastTheLimitIsASyntaxError(): void
    {
        $depth  = NestingLimit::MAX_DEPTH + 1;
        $result = new CliRunner()->run(['yq', '-n', str_repeat('(', $depth) . '1' . str_repeat(')', $depth)]);

        self::assertSame(
            "Error: Bad expression, nested deeper than 10000 levels\n  at offset 10001 of the expression\n",
            $result->stderr,
        );
        self::assertSame('', $result->stdout);
    }

    /**
     * `1 + 1 + ...` with the given number of terms.
     */
    private function sum(int $terms): string
    {
        return implode(' + ', array_fill(0, $terms, '1'));
    }
}
