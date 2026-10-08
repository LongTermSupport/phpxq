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
    public function testAProgramAtTheNestingLimitCompilesAndRuns(): void
    {
        $program = str_repeat('[', NestingLimit::MAX_DEPTH) . '1' . str_repeat(']', NestingLimit::MAX_DEPTH);

        $result = new CliRunner()->run(['jq', '-nc', $program]);

        self::assertSame('', $result->stderr);
        self::assertSame(JqExitCode::OK, $result->exitCode);
        self::assertSame($program . "\n", $result->stdout);
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
