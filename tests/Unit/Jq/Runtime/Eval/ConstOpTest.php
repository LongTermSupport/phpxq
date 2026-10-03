<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ConstOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ConstOp::class)]
final class ConstOpTest extends OpTestCase
{
    public function testEmitsTheConstantWhateverTheInput(): void
    {
        $op = new ConstOp('x');

        self::assertSame(['x'], self::outputs($op, 123));
        self::assertSame('x', $op->value(null, null));
        self::assertSame('x', $op->constant);
    }

    public function testPathModeReportsAComputedValue(): void
    {
        self::assertSame([[null, 1]], self::pathOutputs(new ConstOp(1), null, ['a']));
    }
}
