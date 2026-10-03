<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FuncDefTest extends TestCase
{
    public function testArityCountsClosureAndValueParameters(): void
    {
        $def = new FuncDef('f', ['g', '$x'], new Identity());

        self::assertSame(2, $def->arity());
        self::assertSame('f/2', $def->signature());
        self::assertSame(1, $def->line);
    }
}
