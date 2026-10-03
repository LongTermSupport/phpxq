<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Runtime\Eval\CallOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\FuncInfo;
use LTS\PhpXq\Jq\Runtime\Eval\ParamCallOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(CallOp::class)]
final class CallOpTest extends OpTestCase
{
    public function testTopLevelFunctionRunsWithoutACapturedEnvironment(): void
    {
        $function     = $this->function('f', 0);
        $function->op = self::constant('result');

        self::assertSame(['result'], self::outputs(new CallOp($function, -1, []), 'ignored', new Env(null, 'caller')));
    }

    public function testClosureArgumentsAreBoundToTheCallersEnvironment(): void
    {
        // def f(g): g; called as f($caller) from an environment whose top entry is 'caller'
        $function     = $this->function('f', 1);
        $function->op = new ParamCallOp(0);

        $call = new CallOp($function, -1, [new VarOp(0)]);

        self::assertSame(['caller'], self::outputs($call, null, new Env(null, 'caller')));
    }

    public function testNestedFunctionBodyStartsFromItsDefinitionEntry(): void
    {
        $function     = $this->function('f', 0);
        $function->op = new VarOp(0);

        $definition   = new Env(new Env(null, 'outer'), 'definition-marker');
        $deeper       = new Env($definition, 'later');

        // the definition entry is one step below the call site
        self::assertSame(['definition-marker'], self::outputs(new CallOp($function, 1, []), null, $deeper));
    }

    public function testPathModeRunsTheBodyInPathMode(): void
    {
        $function     = $this->function('f', 0);
        $function->op = new FieldOp('a');

        self::assertSame([[['a'], 1]], self::pathOutputs(new CallOp($function, -1, []), self::object(['a' => 1])));
    }

    private function function(string $name, int $arity): FuncInfo
    {
        $params = [];
        for ($i = 0; $i < $arity; ++$i) {
            $params[] = 'p' . $i;
        }

        return new FuncInfo(new FuncDef($name, $params, new Identity()), null, 0);
    }
}
