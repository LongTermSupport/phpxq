<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\BindVarOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(BindVarOp::class)]
final class BindVarOpTest extends OpTestCase
{
    public function testBindsEverySourceOutput(): void
    {
        $op = new BindVarOp(new IterateOp(null), new VarOp(0));

        self::assertSame([1, 2], self::outputs($op, [1, 2]));
    }

    public function testSingleSourceIsEvaluatedDirectly(): void
    {
        $op = new BindVarOp(self::constant('v'), new VarOp(0));

        self::assertSame(['v'], self::outputs($op));
    }

    public function testPathModeKeepsTheBodyInPathMode(): void
    {
        $op = new BindVarOp(self::generator(1, 2), new IterateOp(null));

        self::assertSame(
            [[[0], 'a'], [[0], 'a']],
            self::pathOutputs($op, ['a']),
        );
    }
}
