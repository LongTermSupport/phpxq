<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Conformance;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Conformance\ConformanceCase;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ConformanceCaseTest extends TestCase
{
    public function testEvaluateReturnsNullOnPass(): void
    {
        $case = new ConformanceCase('a.b', static fn (CliRunner $runner): ?string => null);

        self::assertSame('a.b', $case->id);
        self::assertNull($case->evaluate(new CliRunner()));
    }

    public function testEvaluatePassesTheRunnerAndReturnsTheFailureMessage(): void
    {
        $runner = new CliRunner();
        $seen   = null;
        $case   = new ConformanceCase('x', static function (CliRunner $given) use (&$seen): string {
            $seen = $given;

            return 'broken';
        });

        self::assertSame('broken', $case->evaluate($runner));
        self::assertSame($runner, $seen);
    }
}
