<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\SelectOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SelectOp::class)]
final class SelectOpTest extends OpTestCase
{
    public function testKeepsTheInputWhenTheConditionIsTruthy(): void
    {
        $op = new SelectOp(new FieldOp('keep'));

        self::assertEquals([self::object(['keep' => 1])], self::outputs($op, self::object(['keep' => 1])));
        self::assertSame([], self::outputs($op, self::object(['keep' => false])));
    }

    public function testEveryTruthyConditionOutputEmitsTheInput(): void
    {
        $op = new SelectOp(self::generator([true, false, 1]));

        self::assertSame(['x', 'x'], self::outputs($op, 'x'));
    }

    public function testPathModeKeepsThePath(): void
    {
        $op = new SelectOp(self::generator([true, false, 1]));

        self::assertSame([[['p'], 'x'], [['p'], 'x']], self::pathOutputs($op, 'x', ['p']));
    }
}
