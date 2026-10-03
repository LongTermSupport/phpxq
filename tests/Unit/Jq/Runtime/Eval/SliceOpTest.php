<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\SliceOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SliceOp::class)]
final class SliceOpTest extends OpTestCase
{
    public function testSlicesAnArray(): void
    {
        $op = new SliceOp(new IdentityOp(), self::constant(1), self::constant(3));

        self::assertSame([[1, 2]], self::outputs($op, [0, 1, 2, 3]));
    }

    public function testOpenEnds(): void
    {
        self::assertSame([[2, 3]], self::outputs(new SliceOp(new IdentityOp(), self::constant(2), null), [0, 1, 2, 3]));
        self::assertSame([[0, 1]], self::outputs(new SliceOp(new IdentityOp(), null, self::constant(2)), [0, 1, 2, 3]));
    }

    public function testFromIsTheOuterLoop(): void
    {
        $op = new SliceOp(new IdentityOp(), self::generator([0, 1]), self::generator([1, 2]));

        self::assertSame([[0], [0, 1], [], [1]], self::outputs($op, [0, 1, 2]));
    }

    public function testSlicesAStringByCodepoint(): void
    {
        self::assertSame(['éb'], self::outputs(new SliceOp(new IdentityOp(), self::constant(1), self::constant(3)), 'aébc'));
    }

    public function testNullStaysNull(): void
    {
        self::assertSame([null], self::outputs(new SliceOp(new IdentityOp(), self::constant(1), null), null));
    }

    public function testRejectsNonSliceableValues(): void
    {
        $this->expectException(JqException::class);

        self::outputs(new SliceOp(new IdentityOp(), self::constant(1), null), 5);
    }

    public function testPathModeUsesASliceObjectAsKey(): void
    {
        $op = new SliceOp(new IdentityOp(), self::constant(1), self::constant(2));

        self::assertEquals(
            [[[new JsonObject(['start' => 1, 'end' => 2])], [1]]],
            self::pathOutputs($op, [0, 1, 2]),
        );
    }

    public function testPathModeRejectsAComputedTarget(): void
    {
        $this->expectException(JqException::class);

        self::pathOutputs(new SliceOp(self::constant([1]), self::constant(0), null));
    }
}
