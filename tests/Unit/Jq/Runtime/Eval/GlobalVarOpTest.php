<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\GlobalVarOp;
use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(GlobalVarOp::class)]
final class GlobalVarOpTest extends OpTestCase
{
    public function testReadsTheContextGlobal(): void
    {
        $state = new RunState();
        $state->enter(new StubContext(['name' => 'value']), ['name' => 'value']);

        self::assertSame(['value'], self::outputs(new GlobalVarOp($state, 'name')));
        self::assertNull(new GlobalVarOp($state, 'missing')->value(null, null));
    }

    public function testEnvAndProgArgsHaveDefaults(): void
    {
        $state = new RunState();
        $state->enter(new StubContext(), []);

        self::assertSame([], new GlobalVarOp($state, '__prog_args')->value(null, null));
        self::assertNotNull(new GlobalVarOp($state, 'ENV')->value(null, null));
    }
}
