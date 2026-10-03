<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\NativeStreamOp;
use LTS\PhpXq\Jq\Runtime\Eval\NativeValueOp;
use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use LTS\PhpXq\Jq\Runtime\Eval\SingleNativeOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Jq\Runtime\Filter;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\CallbackPathStreamBuiltin;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\CallbackStreamBuiltin;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\CallbackValueBuiltin;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SingleNativeOp::class)]
#[CoversClass(NativeValueOp::class)]
#[CoversClass(NativeStreamOp::class)]
final class NativeOpsTest extends OpTestCase
{
    public function testSingleNativeCallsOnceWithTheArgumentValues(): void
    {
        $builtin = new CallbackValueBuiltin('join2', 2, static fn (mixed $input, mixed $a, mixed $b): string => $input . $a . $b);
        $op      = new SingleNativeOp($builtin, [self::constant('-'), self::constant('+')], self::state());

        self::assertSame(['x-+'], self::outputs($op, 'x'));
        self::assertSame('x-+', $op->value(null, 'x'));
    }

    public function testSingleNativeWithoutArguments(): void
    {
        $builtin = new CallbackValueBuiltin('upper', 0, static fn (mixed $input): string => strtoupper($input));

        self::assertSame(['ABC'], self::outputs(new SingleNativeOp($builtin, [], self::state()), 'abc'));
    }

    public function testGeneratingArgumentsLoopWithTheLastArgumentOutermost(): void
    {
        $builtin = new CallbackValueBuiltin('pair', 2, static fn (mixed $input, mixed $a, mixed $b): array => [$a, $b]);
        $op      = new NativeValueOp($builtin, [self::generator(['a1', 'a2']), self::generator(['b1', 'b2'])], self::state());

        self::assertSame(
            [['a1', 'b1'], ['a2', 'b1'], ['a1', 'b2'], ['a2', 'b2']],
            self::outputs($op),
        );
    }

    public function testAnEmptyArgumentYieldsNothing(): void
    {
        $builtin = new CallbackValueBuiltin('id', 1, static fn (mixed $input, mixed $a): mixed => $a);

        self::assertSame([], self::outputs(new NativeValueOp($builtin, [self::generator([])], self::state())));
    }

    public function testStreamNativeReceivesBoundFilters(): void
    {
        $seen    = [];
        $builtin = new CallbackStreamBuiltin('each', 1, static function (mixed $input, array $filters, \Closure $emit) use (&$seen): void {
            self::assertContainsOnlyInstancesOf(Filter::class, $filters);
            $filters[0]->run($input, static function (mixed $value) use ($emit, &$seen): void {
                $seen[] = $value;
                $emit($value);
            });
        });
        $op = new NativeStreamOp($builtin, [new VarOp(0)], self::state());

        self::assertSame(['bound'], self::outputs($op, 'input', new Env(null, 'bound')));
        self::assertSame(['bound'], $seen);
    }

    public function testStreamNativeWithoutPathSupportReportsComputedValues(): void
    {
        $builtin = new CallbackStreamBuiltin('same', 0, static function (mixed $input, array $filters, \Closure $emit): void {
            $emit($input);
        });

        self::assertSame([[null, 5]], self::pathOutputs(new NativeStreamOp($builtin, [], self::state()), 5, ['p']));
    }

    public function testPathStreamNativeReceivesThePath(): void
    {
        $op = new NativeStreamOp(new CallbackPathStreamBuiltin('extra', 0), [], self::state());

        self::assertSame(
            [[['p'], 5], [['p', 'extra'], 'extra-value']],
            self::pathOutputs($op, 5, ['p']),
        );
        self::assertSame([5], self::outputs($op, 5));
    }

    private static function state(): RunState
    {
        $state = new RunState();
        $state->enter(new StubContext(), []);

        return $state;
    }
}
