<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ClosureArg;
use LTS\PhpXq\Jq\Runtime\Eval\ConstOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClosureArg::class)]
final class ClosureArgTest extends TestCase
{
    public function testHoldsTheExpressionAndItsEnvironment(): void
    {
        $op  = new ConstOp(1);
        $env = new Env(null, 'x');

        $argument = new ClosureArg($op, $env);

        self::assertSame($op, $argument->op);
        self::assertSame($env, $argument->env);
    }
}
