<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\EmptyOp;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\OperatorOp;
use LTS\PhpXq\Jq\Runtime\Eval\ReduceOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleOperatorOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarBinder;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ReduceOp::class)]
final class ReduceOpTest extends OpTestCase
{
    public function testFoldsTheSource(): void
    {
        $op = new ReduceOp(
            new IterateOp(null),
            new VarBinder(),
            self::constant(0),
            new SingleOperatorOp(new IdentityOp(), new VarOp(0), Arithmetic::add(...)),
        );

        self::assertSame([6], self::outputs($op, [1, 2, 3]));
    }

    public function testEmptySourceYieldsTheInit(): void
    {
        $op = new ReduceOp(self::generator([]), new VarBinder(), self::constant('init'), new IdentityOp());

        self::assertSame(['init'], self::outputs($op));
    }

    public function testOneResultPerInitOutput(): void
    {
        $op = new ReduceOp(
            self::generator([1, 2]),
            new VarBinder(),
            self::generator([0, 10]),
            new SingleOperatorOp(new IdentityOp(), new VarOp(0), Arithmetic::add(...)),
        );

        self::assertSame([3, 13], self::outputs($op));
    }

    public function testAnEmptyUpdateMakesTheStateNull(): void
    {
        $op = new ReduceOp(self::generator([1, 2]), new VarBinder(), self::constant(5), new EmptyOp());

        self::assertSame([null], self::outputs($op));
    }

    public function testTheLastUpdateOutputIsTheNewState(): void
    {
        $op = new ReduceOp(
            self::generator([1]),
            new VarBinder(),
            self::constant(0),
            new OperatorOp(new IdentityOp(), self::generator([10, 20]), Arithmetic::add(...)),
        );

        self::assertSame([20], self::outputs($op));
    }

    public function testPathModeCarriesThePathOfTheState(): void
    {
        $op = new ReduceOp(self::generator([1]), new VarBinder(), new IdentityOp(), new FieldOp('a'));

        self::assertSame([[['a'], 5]], self::pathOutputs($op, self::object(['a' => 5])));
    }

    public function testPathModeWithAnEmptyUpdateLosesThePath(): void
    {
        $op = new ReduceOp(self::generator([1]), new VarBinder(), new IdentityOp(), new EmptyOp());

        self::assertSame([[null, null]], self::pathOutputs($op, 5));
    }
}
