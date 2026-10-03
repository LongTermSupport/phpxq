<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\ArrayOp;
use LTS\PhpXq\Jq\Runtime\Eval\CommaOp;
use LTS\PhpXq\Jq\Runtime\Eval\EmptyOp;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\ForeachOp;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleOperatorOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarBinder;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ForeachOp::class)]
final class ForeachOpTest extends OpTestCase
{
    public function testEmitsEveryIntermediateState(): void
    {
        $op = new ForeachOp(
            new IterateOp(null),
            new VarBinder(),
            self::constant(0),
            new SingleOperatorOp(new IdentityOp(), new VarOp(0), Arithmetic::add(...)),
            null,
        );

        self::assertSame([1, 3, 6], self::outputs($op, [1, 2, 3]));
    }

    public function testExtractSeesTheStateAndTheVariable(): void
    {
        $op = new ForeachOp(
            new IterateOp(null),
            new VarBinder(),
            self::constant(0),
            new SingleOperatorOp(new IdentityOp(), new VarOp(0), Arithmetic::add(...)),
            new ArrayOp(new CommaOp(new VarOp(0), new IdentityOp())),
        );

        self::assertSame([[1, 1], [2, 3]], self::outputs($op, [1, 2]));
    }

    public function testOneRunPerInitOutput(): void
    {
        $op = new ForeachOp(
            new IterateOp(null),
            new VarBinder(),
            self::generator([0, 1]),
            new SingleOperatorOp(new IdentityOp(), new VarOp(0), Arithmetic::add(...)),
            null,
        );

        self::assertSame([1, 3, 2, 4], self::outputs($op, [1, 2]));
    }

    public function testEveryUpdateOutputIsEmittedAndTheLastBecomesTheState(): void
    {
        $op = new ForeachOp(
            self::generator([1, 2]),
            new VarBinder(),
            self::constant(0),
            new CommaOp(new IdentityOp(), new SingleOperatorOp(new IdentityOp(), self::constant(10), Arithmetic::add(...))),
            null,
        );

        // state 0: update emits 0 and 10; state 10: emits 10 and 20
        self::assertSame([0, 10, 10, 20], self::outputs($op));
    }

    public function testAnEmptyUpdateEmitsNothingAndResetsTheState(): void
    {
        $op = new ForeachOp(self::generator([1, 2]), new VarBinder(), self::constant(5), new EmptyOp(), null);

        self::assertSame([], self::outputs($op));
    }

    public function testPathModeFollowsTheUpdateAndExtractPaths(): void
    {
        $op = new ForeachOp(self::generator([1]), new VarBinder(), new IdentityOp(), new FieldOp('a'), new FieldOp('b'));

        self::assertSame(
            [[['a', 'b'], 2]],
            self::pathOutputs($op, self::object(['a' => self::object(['b' => 2])])),
        );
    }

    public function testPathModeWithoutExtract(): void
    {
        $op = new ForeachOp(self::generator([1]), new VarBinder(), new IdentityOp(), new FieldOp('a'), null);

        self::assertSame([[['a'], 1]], self::pathOutputs($op, self::object(['a' => 1])));
    }
}
