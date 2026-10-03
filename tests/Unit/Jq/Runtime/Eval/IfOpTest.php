<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IfOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IfOp::class)]
final class IfOpTest extends OpTestCase
{
    public function testEveryConditionOutputSelectsABranch(): void
    {
        $op = new IfOp(self::generator([1, null, 2]), self::constant('then'), self::constant('else'));

        self::assertSame(['then', 'else', 'then'], self::outputs($op));
    }

    public function testMissingElseIsTheIdentity(): void
    {
        $op = new IfOp(self::constant(false), self::constant('then'), null);

        self::assertSame(['input'], self::outputs($op, 'input'));
    }

    public function testEmptyConditionYieldsNothing(): void
    {
        self::assertSame([], self::outputs(new IfOp(self::generator([]), self::constant(1), null)));
    }

    public function testGeneratingBranchesEmitEveryOutput(): void
    {
        $op = new IfOp(self::constant(true), self::generator([1, 2]), null);

        self::assertSame([1, 2], self::outputs($op));
    }

    public function testConditionIsEvaluatedAgainstTheInput(): void
    {
        $op = new IfOp(new FieldOp('flag'), self::constant('yes'), self::constant('no'));

        self::assertSame(['yes'], self::outputs($op, self::object(['flag' => 1])));
        self::assertSame(['no'], self::outputs($op, self::object(['flag' => false])));
    }

    public function testPathModeEvaluatesTheConditionAsAValueAndThePickedBranchAsAPath(): void
    {
        $op    = new IfOp(self::generator([true, false]), new FieldOp('a'), new FieldOp('b'));
        $input = self::object(['a' => 1, 'b' => 2]);

        self::assertSame([[['a'], 1], [['b'], 2]], self::pathOutputs($op, $input));
    }

    public function testPathModeWithoutElseKeepsThePath(): void
    {
        $op = new IfOp(self::generator([false]), self::constant(1), null);

        self::assertSame([[['p'], 'v']], self::pathOutputs($op, 'v', ['p']));
    }

    public function testSingleConditionTakesTheDirectRoute(): void
    {
        $op = new IfOp(self::constant(null), self::constant('then'), null);

        self::assertSame(['v'], self::outputs($op, 'v'));
        self::assertSame([[['p'], 'v']], self::pathOutputs($op, 'v', ['p']));
    }
}
