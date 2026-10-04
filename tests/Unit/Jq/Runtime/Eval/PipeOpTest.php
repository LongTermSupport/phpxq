<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\PipeOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(PipeOp::class)]
final class PipeOpTest extends OpTestCase
{
    public function testFeedsEveryLeftOutputToTheRight(): void
    {
        $op = new PipeOp(new IterateOp(null), new FieldOp('a'));

        self::assertSame([1, 2], self::outputs($op, [self::object(['a' => 1]), self::object(['a' => 2])]));
    }

    public function testEmptyLeftYieldsNothing(): void
    {
        self::assertSame([], self::outputs(new PipeOp(self::generator(), self::constant(1))));
    }

    public function testPathModeThreadsThePath(): void
    {
        $op = new PipeOp(new IterateOp(null), new FieldOp('a'));

        self::assertSame(
            [[[0, 'a'], 1], [[1, 'a'], 2]],
            self::pathOutputs($op, [self::object(['a' => 1]), self::object(['a' => 2])]),
        );
    }
}
