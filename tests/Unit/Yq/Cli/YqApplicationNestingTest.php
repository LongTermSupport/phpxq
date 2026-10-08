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
 * A long chain is not nesting, so it runs as Go yq runs it; real nesting past the limit is a syntax error. The
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
        $sum    = new CliRunner()->run(['yq', '-n', implode(' + ', array_fill(0, self::LONG_CHAIN, '1'))]);
        $fields = new CliRunner()->run(['yq', '-n', str_repeat('.a', self::LONG_CHAIN)]);
        $pipes  = new CliRunner()->run(['yq', '-n', '1' . str_repeat(' | . + 1', self::LONG_CHAIN)]);

        self::assertSame(['', self::LONG_CHAIN . "\n"], [$union->stderr, $union->stdout]);
        self::assertSame(['', self::LONG_CHAIN . "\n"], [$sum->stderr, $sum->stdout]);
        self::assertSame(['', "null\n"], [$fields->stderr, $fields->stdout]);
        self::assertSame(['', (self::LONG_CHAIN + 1) . "\n"], [$pipes->stderr, $pipes->stdout]);
    }

    public function testNestingPastTheLimitIsASyntaxError(): void
    {
        $depth  = NestingLimit::MAX_DEPTH + 1;
        $result = new CliRunner()->run(['yq', '-n', str_repeat('(', $depth) . '1' . str_repeat(')', $depth)]);

        self::assertSame("Error: Bad expression, nested deeper than 10000 levels\n", $result->stderr);
        self::assertSame('', $result->stdout);
    }
}
