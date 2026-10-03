<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\SinglePipeOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SinglePipeOp::class)]
final class SinglePipeOpTest extends OpTestCase
{
    public function testComposesTwoSingleOps(): void
    {
        $op    = new SinglePipeOp(new FieldOp('a'), new FieldOp('b'));
        $input = self::object(['a' => self::object(['b' => 5])]);

        self::assertSame([5], self::outputs($op, $input));
        self::assertSame(5, $op->value(null, $input));
    }

    public function testPathModeThreadsThePath(): void
    {
        $op = new SinglePipeOp(new FieldOp('a'), new FieldOp('b'));

        self::assertSame(
            [[['a', 'b'], 5]],
            self::pathOutputs($op, self::object(['a' => self::object(['b' => 5])])),
        );
    }
}
