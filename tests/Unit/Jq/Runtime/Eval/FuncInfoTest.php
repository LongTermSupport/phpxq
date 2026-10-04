<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LogicException;
use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Runtime\Eval\DefSet;
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

    public function testDeferredDefinitionIsParsedOnceOnFirstUse(): void
    {
        $parses = 0;
        $info   = FuncInfo::deferred('g/1', static function () use (&$parses): FuncDef {
            ++$parses;

            return new FuncDef('g', ['x'], new Identity());
        }, new DefSet(), 3);

        self::assertSame('g/1', $info->signature());
        self::assertSame(0, $parses, 'asking for the signature must not parse');
        self::assertTrue($info->prelude);
        self::assertSame(3, $info->limit);

        self::assertSame('g', $info->definition()->name);
        self::assertSame($info->definition(), $info->definition());
        self::assertSame(1, $parses);
    }

    public function testSignatureOfAnEagerDefinitionComesFromTheDefinition(): void
    {
        self::assertSame('f/2', new FuncInfo(new FuncDef('f', ['a', 'b'], new Identity()), null, 0)->signature());
    }

    public function testNeedsADefinitionOrAParser(): void
    {
        $this->expectException(LogicException::class);

        new FuncInfo(null, null, 0);
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
