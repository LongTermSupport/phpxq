<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(VarOp::class)]
final class VarOpTest extends OpTestCase
{
    public function testReadsTheEntryAtTheGivenDepth(): void
    {
        $env = new Env(new Env(new Env(null, 'outer'), 'middle'), 'inner');

        self::assertSame(['inner'], self::outputs(new VarOp(0), null, $env));
        self::assertSame(['middle'], self::outputs(new VarOp(1), null, $env));
        self::assertSame('outer', new VarOp(2)->value($env, null));
    }

    public function testPathModeReportsAComputedValue(): void
    {
        self::assertSame([[null, 'v']], self::pathOutputs(new VarOp(0), null, ['a'], new Env(null, 'v')));
    }
}
