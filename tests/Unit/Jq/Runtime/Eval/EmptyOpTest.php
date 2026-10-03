<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\EmptyOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EmptyOp::class)]
final class EmptyOpTest extends OpTestCase
{
    public function testEmitsNothing(): void
    {
        self::assertSame([], self::outputs(new EmptyOp(), 1));
        self::assertSame([], self::pathOutputs(new EmptyOp(), 1));
    }
}
