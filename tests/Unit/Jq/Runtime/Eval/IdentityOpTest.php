<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IdentityOp::class)]
final class IdentityOpTest extends OpTestCase
{
    public function testEmitsItsInput(): void
    {
        $op = new IdentityOp();

        self::assertSame([[1]], self::outputs($op, [1]));
        self::assertSame([1], self::outputs($op, 1));
        self::assertSame(1, $op->value(null, 1));
    }

    public function testPathModeKeepsThePathAndTheValue(): void
    {
        self::assertSame([[['a'], 5]], self::pathOutputs(new IdentityOp(), 5, ['a']));
        self::assertSame([[null, 5]], self::pathOutputs(new IdentityOp(), 5, null));
    }
}
