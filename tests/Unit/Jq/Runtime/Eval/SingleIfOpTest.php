<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleIfOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SingleIfOp::class)]
final class SingleIfOpTest extends OpTestCase
{
    public function testPicksTheBranch(): void
    {
        $op = new SingleIfOp(new FieldOp('f'), self::constant('then'), self::constant('else'));

        self::assertSame('then', $op->value(null, self::object(['f' => 1])));
        self::assertSame('else', $op->value(null, self::object(['f' => null])));
        self::assertSame(['then'], self::outputs($op, self::object(['f' => 0])));
    }

    public function testMissingElseIsTheIdentity(): void
    {
        $op = new SingleIfOp(self::constant(false), self::constant('then'), null);

        self::assertSame('input', $op->value(null, 'input'));
        self::assertSame([[['p'], 'input']], self::pathOutputs($op, 'input', ['p']));
    }

    public function testPathModeFollowsThePickedBranch(): void
    {
        $op = new SingleIfOp(new FieldOp('f'), new FieldOp('a'), new FieldOp('b'));

        self::assertSame(
            [[['a'], 1]],
            self::pathOutputs($op, self::object(['f' => true, 'a' => 1, 'b' => 2])),
        );
        self::assertSame(
            [[['b'], 2]],
            self::pathOutputs($op, self::object(['f' => false, 'a' => 1, 'b' => 2])),
        );
    }
}
