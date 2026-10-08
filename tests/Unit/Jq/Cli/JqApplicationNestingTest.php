<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Cli\JqExitCode;
use LTS\PhpXq\Limits\NestingLimit;
use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A deeply nested program is parsed and compiled on the evaluation fiber, so it fails with a compile error
 * past the nesting limit and runs below it, instead of overflowing the process stack.
 *
 * @internal
 */
#[CoversClass(JqApplication::class)]
#[Medium]
final class JqApplicationNestingTest extends TestCase
{
    private const int LONG_CHAIN = 100000;

    public function testAProgramAtTheNestingLimitCompilesAndRuns(): void
    {
        $program = str_repeat('[', NestingLimit::MAX_DEPTH) . '1' . str_repeat(']', NestingLimit::MAX_DEPTH);

        $result = new CliRunner()->run(['jq', '-nc', $program]);

        self::assertSame('', $result->stderr);
        self::assertSame(JqExitCode::OK, $result->exitCode);
        self::assertSame($program . "\n", $result->stdout);
    }

    /**
     * A long chain is not nesting: jq 1.6 runs both, and a hundred thousand array elements used to overflow
     * the stack under a coverage driver.
     */
    public function testLongChainsCompileAndRun(): void
    {
        $elements = new CliRunner()->run(['jq', '-n', '[' . implode(',', array_fill(0, self::LONG_CHAIN, '0')) . '] | length']);
        $sum      = new CliRunner()->run(['jq', '-n', implode('+', array_fill(0, self::LONG_CHAIN, '1'))]);
        $fields   = new CliRunner()->run(['jq', '-n', '{} | ' . str_repeat('.a', self::LONG_CHAIN)]);

        self::assertSame(['', self::LONG_CHAIN . "\n"], [$elements->stderr, $elements->stdout]);
        self::assertSame(['', self::LONG_CHAIN . "\n"], [$sum->stderr, $sum->stdout]);
        self::assertSame(['', "null\n"], [$fields->stderr, $fields->stdout]);
    }

    public function testAProgramPastTheNestingLimitIsACompileError(): void
    {
        $depth   = 2 * NestingLimit::MAX_DEPTH;
        $program = str_repeat('(', $depth) . '1' . str_repeat(')', $depth);

        $result = new CliRunner()->run(['jq', '-n', $program]);

        self::assertSame(JqExitCode::COMPILE_ERROR, $result->exitCode);
        self::assertStringStartsWith('jq: error: syntax error, program nested deeper than 10000 levels at <top-level>, line 1, column 10002:', $result->stderr);
        self::assertSame('', $result->stdout);
    }
}
