<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LogicException;
use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Runtime\Eval\FuncInfo;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FuncInfo::class)]
final class FuncInfoTest extends TestCase
{
    use AssertsRaised;

    public function testBodyIsUnavailableUntilCompiled(): void
    {
        $info = new FuncInfo(new FuncDef('f', [], new Identity()), null, 0);

        self::assertRaises(LogicException::class, 'Function f/0 is not compiled yet', static fn (): mixed => $info->body());
    }

    public function testBodyReturnsTheCompiledOp(): void
    {
        $info     = new FuncInfo(new FuncDef('f', ['g'], new Identity()), null, 0);
        $op       = new IdentityOp();
        $info->op = $op;

        self::assertSame($op, $info->body());
        self::assertFalse($info->compiling);
        self::assertFalse($info->prelude);
    }
}
