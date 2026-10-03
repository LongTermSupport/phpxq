<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\ArithAssignOp;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArithAssignOp::class)]
final class ArithAssignOpTest extends OpTestCase
{
    public function testAppliesTheOperationToEveryPath(): void
    {
        $op = new ArithAssignOp(new IterateOp(null), self::constant(2), Arithmetic::add(...));

        self::assertSame([[3, 5, 7]], self::outputs($op, [1, 3, 5]));
    }

    public function testOneOutputPerRightHandValue(): void
    {
        $op = new ArithAssignOp(new FieldOp('a'), self::generator([1, 10]), Arithmetic::add(...));

        self::assertEquals(
            [self::object(['a' => 2]), self::object(['a' => 11])],
            self::outputs($op, self::object(['a' => 1])),
        );
    }

    public function testTheRightHandSideIsEvaluatedAgainstTheInput(): void
    {
        $op = new ArithAssignOp(new FieldOp('a'), new FieldOp('a'), Arithmetic::add(...));

        self::assertEquals([self::object(['a' => 4])], self::outputs($op, self::object(['a' => 2])));
    }

    public function testAlternativeOperation(): void
    {
        $op = new ArithAssignOp(
            new IterateOp(null),
            self::constant('x'),
            static fn (mixed $old, mixed $operand): mixed => null !== $old && false !== $old ? $old : $operand,
        );

        self::assertSame([['hello', true, 'x', [false], 'x']], self::outputs($op, ['hello', true, false, [false], null]));
    }
}
