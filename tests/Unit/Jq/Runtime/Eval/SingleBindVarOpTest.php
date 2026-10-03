<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleBindVarOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleOperatorOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SingleBindVarOp::class)]
final class SingleBindVarOpTest extends OpTestCase
{
    public function testBindsTheSourceValueForTheBody(): void
    {
        $op = new SingleBindVarOp(
            new FieldOp('x'),
            new SingleOperatorOp(new VarOp(0), new VarOp(0), Arithmetic::add(...)),
        );

        self::assertSame([6], self::outputs($op, self::object(['x' => 3])));
        self::assertSame(6, $op->value(null, self::object(['x' => 3])));
    }

    public function testPathModeFollowsTheBody(): void
    {
        $op = new SingleBindVarOp(self::constant(1), new FieldOp('a'));

        self::assertSame([[['a'], 5]], self::pathOutputs($op, self::object(['a' => 5])));
    }
}
