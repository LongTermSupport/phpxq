<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LogicException;
use LTS\PhpXq\Jq\Runtime\Eval\ClosureArg;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\ParamCallOp;
use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ParamCallOp::class)]
final class ParamCallOpTest extends OpTestCase
{
    public function testRunsTheArgumentInItsOwnEnvironment(): void
    {
        $caller = new Env(null, 'in-caller');
        $frame  = new Env(null, new ClosureArg(new VarOp(0), $caller));

        self::assertSame(['in-caller'], self::outputs(new ParamCallOp(0, new RunState()), 'input', $frame));
    }

    public function testTheArgumentReceivesTheCallsInput(): void
    {
        $frame = new Env(null, new ClosureArg(new FieldOp('a'), null));

        self::assertSame([1], self::outputs(new ParamCallOp(0, new RunState()), self::object(['a' => 1]), $frame));
    }

    public function testPathModeDelegates(): void
    {
        $frame = new Env(new Env(null, 'x'), new ClosureArg(new FieldOp('a'), null));

        self::assertSame([[['p', 'a'], 1]], self::pathOutputs(new ParamCallOp(0, new RunState()), self::object(['a' => 1]), ['p'], $frame));
    }

    public function testDepthSelectsTheParameter(): void
    {
        $frame = new Env(new Env(null, new ClosureArg(self::constant('first'), null)), new ClosureArg(self::constant('second'), null));

        self::assertSame(['first'], self::outputs(new ParamCallOp(1, new RunState()), null, $frame));
    }

    public function testAnUnboundParameterIsAnInternalError(): void
    {
        $this->expectException(LogicException::class);

        self::outputs(new ParamCallOp(0, new RunState()), null, new Env(null, 'not a closure'));
    }
}
