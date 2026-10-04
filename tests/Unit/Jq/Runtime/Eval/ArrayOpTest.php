<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ArrayOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ArrayOp::class)]
final class ArrayOpTest extends OpTestCase
{
    public function testWithoutBodyIsTheEmptyArray(): void
    {
        self::assertSame([[]], self::outputs(new ArrayOp(null)));
    }

    public function testCollectsEveryOutput(): void
    {
        self::assertSame([[1, 2, 3]], self::outputs(new ArrayOp(new IterateOp(null)), [1, 2, 3]));
        self::assertSame([[]], self::outputs(new ArrayOp(self::generator())));
    }

    public function testSingleBody(): void
    {
        self::assertSame([[7]], self::outputs(new ArrayOp(self::constant(7))));
    }

    public function testPathModeReportsAComputedValue(): void
    {
        self::assertSame([[null, [1]]], self::pathOutputs(new ArrayOp(self::constant(1))));
    }
}
