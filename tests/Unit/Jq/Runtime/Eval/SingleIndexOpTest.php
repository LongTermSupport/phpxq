<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleIndexOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SingleIndexOp::class)]
final class SingleIndexOpTest extends OpTestCase
{
    public function testIndexesTheTargetByTheKey(): void
    {
        $op = new SingleIndexOp(self::constant([5, 6, 7]), self::constant(-1));

        self::assertSame([7], self::outputs($op));
        self::assertSame(7, $op->value(null, null));
    }

    public function testNegativeAndOutOfRangeIndexes(): void
    {
        self::assertNull(new SingleIndexOp(self::constant([1]), self::constant(5))->value(null, null));
        self::assertNull(new SingleIndexOp(self::constant([1]), self::constant(-5))->value(null, null));
    }

    public function testPathModeAppendsTheKeyToTheTargetsPath(): void
    {
        $op = new SingleIndexOp(new IdentityOp(), self::constant(1));

        self::assertSame([[['x', 1], 'b']], self::pathOutputs($op, ['a', 'b'], ['x']));
    }

    public function testPathModeRejectsAComputedTarget(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Invalid path expression near attempt to access element 0 of [1]');

        self::pathOutputs(new SingleIndexOp(self::constant([1]), self::constant(0)));
    }
}
