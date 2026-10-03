<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\Cmp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleOperatorOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SingleOperatorOp::class)]
final class SingleOperatorOpTest extends OpTestCase
{
    public function testAppliesTheOperationToBothValues(): void
    {
        $op = new SingleOperatorOp(self::constant(5), self::constant(3), Arithmetic::subtract(...));

        self::assertSame([2], self::outputs($op));
        self::assertSame(2, $op->value(null, null));
    }

    public function testComparison(): void
    {
        $op = new SingleOperatorOp(self::constant(1), self::constant(2), Cmp::lt(...));

        self::assertTrue($op->value(null, null));
    }

    public function testPathModeReportsAComputedValue(): void
    {
        $op = new SingleOperatorOp(self::constant(1), self::constant(2), Arithmetic::add(...));

        self::assertSame([[null, 3]], self::pathOutputs($op));
    }
}
