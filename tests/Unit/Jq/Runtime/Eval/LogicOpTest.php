<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\Eval\LogicOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(LogicOp::class)]
final class LogicOpTest extends OpTestCase
{
    public function testAndHasTheLeftOperandAsOuterLoop(): void
    {
        $op = new LogicOp(self::generator(true, false), self::generator(true, false), true);

        self::assertSame([true, false, false], self::outputs($op));
    }

    public function testOrHasTheLeftOperandAsOuterLoop(): void
    {
        $op = new LogicOp(self::generator(true, false), self::generator(true, false), false);

        self::assertSame([true, true, false], self::outputs($op));
    }

    public function testTheRightOperandIsOnlyEvaluatedWhenNeeded(): void
    {
        $right = new class extends \LTS\PhpXq\Jq\Runtime\Eval\AbstractOp {
            public int $evaluated = 0;

            public function run(?\LTS\PhpXq\Jq\Runtime\Eval\Env $env, mixed $input, Closure $emit): void
            {
                ++$this->evaluated;
                $emit(true);
            }
        };

        self::outputs(new LogicOp(self::constant(false), $right, true));
        self::outputs(new LogicOp(self::constant(true), $right, false));
        self::assertSame(0, $right->evaluated);

        self::outputs(new LogicOp(self::constant(true), $right, true));
        self::assertSame(1, $right->evaluated);
    }

    public function testResultsAreBooleans(): void
    {
        self::assertSame([true], self::outputs(new LogicOp(self::constant(1), self::constant('x'), true)));
        self::assertSame([false], self::outputs(new LogicOp(self::constant(null), self::constant(null), false)));
    }
}
