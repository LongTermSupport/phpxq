<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\ValuePipeOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ValuePipeOp::class)]
final class ValuePipeOpTest extends OpTestCase
{
    public function testRunsTheRightOnTheSingleLeftValue(): void
    {
        $op = new ValuePipeOp(new FieldOp('xs'), new IterateOp(null));

        self::assertSame([1, 2], self::outputs($op, self::object(['xs' => [1, 2]])));
    }

    public function testPathModeThreadsThePath(): void
    {
        $op = new ValuePipeOp(new FieldOp('xs'), new IterateOp(null));

        self::assertSame(
            [[['xs', 0], 1], [['xs', 1], 2]],
            self::pathOutputs($op, self::object(['xs' => [1, 2]])),
        );
    }
}
