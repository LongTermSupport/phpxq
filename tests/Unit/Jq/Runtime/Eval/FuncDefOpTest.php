<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\FuncDefOp;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(FuncDefOp::class)]
final class FuncDefOpTest extends OpTestCase
{
    public function testPushesTheDefinitionEntryForTheRest(): void
    {
        $env = new Env(null, 'x');
        // inside the rest, the definition marker is depth 0 (value null) and x is depth 1
        self::assertSame([null], self::outputs(new FuncDefOp(new VarOp(0)), null, $env));
        self::assertSame(['x'], self::outputs(new FuncDefOp(new VarOp(1)), null, $env));
    }

    public function testPathModeDelegatesToTheRest(): void
    {
        self::assertSame([[null, null]], self::pathOutputs(new FuncDefOp(new VarOp(0))));
    }
}
