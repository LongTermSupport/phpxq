<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\SetAssignOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SetAssignOp::class)]
final class SetAssignOpTest extends OpTestCase
{
    public function testSetsEveryPath(): void
    {
        $op = new SetAssignOp(new IterateOp(null), self::constant(0));

        self::assertSame([[0, 0, 0]], self::outputs($op, [1, 2, 3]));
    }

    public function testOneOutputPerRightHandValue(): void
    {
        $op = new SetAssignOp(new FieldOp('a'), self::generator([1, 2]));

        self::assertEquals(
            [self::object(['a' => 1]), self::object(['a' => 2])],
            self::outputs($op, self::object(['a' => 0])),
        );
    }

    public function testTheRightHandSideSeesTheOriginalInput(): void
    {
        $op = new SetAssignOp(new FieldOp('a'), new FieldOp('b'));

        self::assertEquals([self::object(['a' => 2, 'b' => 2])], self::outputs($op, self::object(['a' => 1, 'b' => 2])));
    }

    public function testCreatesMissingStructure(): void
    {
        self::assertEquals([self::object(['a' => 1])], self::outputs(new SetAssignOp(new FieldOp('a'), self::constant(1)), null));
    }

    public function testEmptyRightHandSideYieldsNothing(): void
    {
        self::assertSame([], self::outputs(new SetAssignOp(new FieldOp('a'), self::generator([])), null));
    }

    public function testTheLeftSideMustBeAPathExpression(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Invalid path expression with result 1');

        self::outputs(new SetAssignOp(self::constant(1), self::constant(2)), 1);
    }
}
