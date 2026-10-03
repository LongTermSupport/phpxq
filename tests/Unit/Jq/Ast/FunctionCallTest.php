<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FunctionCallTest extends TestCase
{
    public function testSignatureIsNameSlashArity(): void
    {
        $call = new FunctionCall('a::f', [new Identity(), new Identity()], 3);

        self::assertSame(2, $call->arity());
        self::assertSame('a::f/2', $call->signature());
        self::assertSame(3, $call->line);
    }

    public function testArgumentsDefaultToNone(): void
    {
        self::assertSame('empty/0', new FunctionCall('empty')->signature());
    }
}
