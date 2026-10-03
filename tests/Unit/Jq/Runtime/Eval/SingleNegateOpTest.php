<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\SingleNegateOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SingleNegateOp::class)]
final class SingleNegateOpTest extends OpTestCase
{
    public function testNegates(): void
    {
        $op = new SingleNegateOp(self::constant(3));

        self::assertSame([-3], self::outputs($op));
        self::assertSame(-3, $op->value(null, null));
    }

    public function testNegativeZeroKeepsItsSign(): void
    {
        $zero = new SingleNegateOp(self::constant(0))->value(null, null);

        self::assertIsFloat($zero);
        self::assertSame(0.0, abs($zero));
        self::assertLessThan(0, fdiv(1.0, $zero));
    }
}
