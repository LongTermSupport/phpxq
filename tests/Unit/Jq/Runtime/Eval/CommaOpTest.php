<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\CommaOp;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(CommaOp::class)]
final class CommaOpTest extends OpTestCase
{
    public function testEmitsLeftThenRight(): void
    {
        self::assertSame([1, 2, 3], self::outputs(new CommaOp(self::generator(1, 2), self::constant(3))));
    }

    public function testPathModeEmitsBothSides(): void
    {
        $op = new CommaOp(new FieldOp('a'), new FieldOp('b'));

        self::assertSame(
            [[['a'], 1], [['b'], 2]],
            self::pathOutputs($op, self::object(['a' => 1, 'b' => 2])),
        );
    }
}
