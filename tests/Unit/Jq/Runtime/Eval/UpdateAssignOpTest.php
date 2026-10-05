<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\Eval\AbstractOp;
use LTS\PhpXq\Jq\Runtime\Eval\Cmp;
use LTS\PhpXq\Jq\Runtime\Eval\CommaOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\SelectOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleOperatorOp;
use LTS\PhpXq\Jq\Runtime\Eval\UpdateAssignOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(UpdateAssignOp::class)]
final class UpdateAssignOpTest extends OpTestCase
{
    public function testUpdatesEveryPath(): void
    {
        $op = new UpdateAssignOp(new IterateOp(null), new SingleOperatorOp(new IdentityOp(), self::constant(1), Arithmetic::add(...)));

        self::assertSame([[2, 3]], self::outputs($op, [1, 2]));
    }

    public function testTheFirstOutputOfAGeneratingUpdateIsUsed(): void
    {
        $op = new UpdateAssignOp(new FieldOp('a'), self::generator(1, 2));

        self::assertEquals([self::object(['a' => 1])], self::outputs($op, self::object(['a' => 0])));
    }

    public function testAnEmptyUpdateDeletesThePath(): void
    {
        $op = new UpdateAssignOp(new FieldOp('a'), self::generator());

        self::assertEquals([self::object(['b' => 2])], self::outputs($op, self::object(['a' => 1, 'b' => 2])));
    }

    public function testDeletionsHappenAfterAllUpdates(): void
    {
        $op = new UpdateAssignOp(new IterateOp(null), new SelectOp(new SingleOperatorOp(new IdentityOp(), self::constant(2), Cmp::lt(...))));

        self::assertSame([[1, 0]], self::outputs($op, [1, 5, 3, 0, 7]));
    }

    public function testOnlyTheFirstUpdateOutputIsEvaluated(): void
    {
        $update = new CommaOp(self::constant('first'), new class extends AbstractOp {
            public function run(?Env $env, mixed $input, Closure $emit): void
            {
                throw new BreakException(new stdClass());
            }
        });
        $op = new UpdateAssignOp(new IterateOp(null), $update);

        self::assertSame([['first', 'first']], self::outputs($op, [1, 2]));
    }

    public function testAForeignBreakPropagates(): void
    {
        $update = new class extends AbstractOp {
            public function run(?Env $env, mixed $input, Closure $emit): void
            {
                throw new BreakException(new stdClass());
            }
        };

        $this->expectException(BreakException::class);

        self::outputs(new UpdateAssignOp(new IterateOp(null), $update), [1]);
    }

    public function testPathModeReportsAComputedValue(): void
    {
        $op = new UpdateAssignOp(new FieldOp('a'), self::constant(1));

        self::assertEquals([[null, self::object(['a' => 1])]], self::pathOutputs($op, self::object(['a' => 0])));
    }
}
