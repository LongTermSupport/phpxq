<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    public function testAtWalksTheRequestedNumberOfEntries(): void
    {
        $outer = new Env(null, 'outer');
        $inner = new Env($outer, 'inner');

        self::assertSame($inner, Env::at($inner, 0));
        self::assertSame($outer, Env::at($inner, 1));
        self::assertNull(Env::at($inner, 2));
        self::assertSame('outer', Env::at($inner, 1)?->value);
    }
}
