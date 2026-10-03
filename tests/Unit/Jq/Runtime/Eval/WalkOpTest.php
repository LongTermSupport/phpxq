<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ConstOp;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleWalkOp;
use LTS\PhpXq\Jq\Runtime\Eval\WalkOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(WalkOp::class)]
#[CoversClass(SingleWalkOp::class)]
final class WalkOpTest extends OpTestCase
{
    public function testIdentityFilterReturnsTheInputUnchanged(): void
    {
        $input = [1, self::object(['a' => [2, self::object(['b' => 3])]])];

        self::assertEquals([$input], self::outputs(new WalkOp(self::identity()), $input));
        self::assertEquals([$input], self::outputs(new SingleWalkOp(self::identity()), $input));
    }

    public function testScalarsAreFilteredDirectly(): void
    {
        self::assertSame([7], self::outputs(new SingleWalkOp(new ConstOp(7)), 'x'));
        self::assertSame([7], self::outputs(new WalkOp(new ConstOp(7)), 'x'));
    }

    public function testArrayElementsKeepEveryOutputOfTheFilter(): void
    {
        $op = new WalkOp(self::generator([1, 2]));

        // each element yields 1 and 2; the array itself is then filtered to 1 and 2 as well
        self::assertSame([1, 2], self::outputs($op, [0, 0]));
    }

    public function testObjectMembersTakeTheFirstOutputAndDropWhenEmpty(): void
    {
        $first = new WalkOp(self::generator([1, 2]));
        self::assertSame([1, 2], self::outputs($first, self::object(['a' => 0])));

        $none = new WalkOp(self::generator([]));
        self::assertSame([], self::outputs($none, self::object(['a' => 0])));
    }

    public function testPathModeReportsNoPath(): void
    {
        self::assertSame([[null, 5]], self::pathOutputs(new WalkOp(new ConstOp(5)), 'x', ['p']));
        self::assertSame([[null, 5]], self::pathOutputs(new SingleWalkOp(new ConstOp(5)), 'x', ['p']));
    }
}
