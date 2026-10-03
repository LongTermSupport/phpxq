<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\IndexOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(IndexOp::class)]
final class IndexOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testIndexIsTheOuterLoopAndTargetTheInnerOne(): void
    {
        $op = new IndexOp(self::generator([[10, 11], [20, 21]]), self::generator([0, 1]));

        self::assertSame([10, 20, 11, 21], self::outputs($op));
    }

    public function testPathModeAppendsTheKey(): void
    {
        $op = new IndexOp(new IdentityOp(), self::generator(['a', 'b']));

        self::assertSame(
            [[['a'], 1], [['b'], 2]],
            self::pathOutputs($op, self::object(['a' => 1, 'b' => 2])),
        );
    }

    public function testPathModeRejectsAComputedTarget(): void
    {
        self::assertRaises(JqException::class, 'Invalid path expression near attempt to access element 0 of [1]', static fn (): mixed => self::pathOutputs(new IndexOp(self::constant([1]), self::constant(0))));
    }
}
