<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ArrayBinder;
use LTS\PhpXq\Jq\Runtime\Eval\BindOp;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarBinder;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(BindOp::class)]
final class BindOpTest extends OpTestCase
{
    public function testBodyRunsPerSourceOutputAgainstTheOriginalInput(): void
    {
        $op = new BindOp(new IterateOp(null), new ArrayBinder([new VarBinder(), new VarBinder()]), new VarOp(0));

        self::assertSame([2, 4], self::outputs($op, [[1, 2], [3, 4]]));
    }

    public function testTheBodyInputIsTheOriginalInput(): void
    {
        $op = new BindOp(self::constant([9]), new ArrayBinder([new VarBinder()]), new IdentityOp());

        self::assertSame([['in']], self::outputs($op, ['in']));
    }

    public function testPathModeKeepsTheBodyInPathMode(): void
    {
        $op = new BindOp(self::constant(1), new VarBinder(), new IterateOp(null));

        self::assertSame([[[0], 'a'], [[1], 'b']], self::pathOutputs($op, ['a', 'b']));
    }
}
