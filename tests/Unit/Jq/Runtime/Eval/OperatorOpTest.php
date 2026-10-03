<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\OperatorOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OperatorOp::class)]
final class OperatorOpTest extends OpTestCase
{
    public function testRightOperandIsTheOuterLoop(): void
    {
        $op = new OperatorOp(self::generator([1, 2]), self::generator([10, 20]), Arithmetic::add(...));

        self::assertSame([11, 12, 21, 22], self::outputs($op));
    }

    public function testSingleRightOperand(): void
    {
        $op = new OperatorOp(self::generator([1, 2]), self::constant(10), Arithmetic::add(...));

        self::assertSame([11, 12], self::outputs($op));
    }

    public function testSingleLeftOperand(): void
    {
        $op = new OperatorOp(self::constant(1), self::generator([10, 20]), Arithmetic::add(...));

        self::assertSame([11, 21], self::outputs($op));
    }

    public function testEmptyOperandYieldsNothing(): void
    {
        $op = new OperatorOp(self::constant(1), self::generator([]), Arithmetic::add(...));

        self::assertSame([], self::outputs($op));
    }

    public function testPathModeReportsComputedValues(): void
    {
        $op = new OperatorOp(self::generator([1]), self::generator([2]), Arithmetic::add(...));

        self::assertSame([[null, 3]], self::pathOutputs($op));
    }
}
