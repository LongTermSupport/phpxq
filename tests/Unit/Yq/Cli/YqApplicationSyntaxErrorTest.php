<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A malformed expression keeps the reference's first line and then says where in the expression the parser stopped.
 */
#[CoversNothing]
#[Medium]
final class YqApplicationSyntaxErrorTest extends TestCase
{
    public function testTheErrorNamesTheOffsetOfTheProblem(): void
    {
        $result = new CliRunner()->run(['yq', '-n', '1 +']);

        self::assertSame(
            "Error: Bad expression, please check expression syntax\n  at offset 3 of the expression\n",
            $result->stderr,
        );
        self::assertSame('', $result->stdout);
    }

    public function testAProblemInTheMiddleNamesItsOwnOffset(): void
    {
        $result = new CliRunner()->run(['yq', '-n', '.a | | .b']);

        self::assertSame(
            "Error: Bad expression, please check expression syntax\n  at offset 5 of the expression\n",
            $result->stderr,
        );
    }
}
