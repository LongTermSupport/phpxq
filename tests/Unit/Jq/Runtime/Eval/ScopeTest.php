<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Runtime\Eval\FuncInfo;
use LTS\PhpXq\Jq\Runtime\Eval\Scope;
use LTS\PhpXq\Jq\Runtime\Eval\ScopeKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Scope::class)]
#[CoversClass(ScopeKindEnum::class)]
final class ScopeTest extends TestCase
{
    public function testVariableDepthCountsEveryEntry(): void
    {
        $scope = Scope::variable(Scope::param(Scope::variable(null, 'a'), 'g'), 'b');

        self::assertSame(0, $scope->depthOfVariable('b'));
        self::assertSame(2, $scope->depthOfVariable('a'));
        self::assertNull($scope->depthOfVariable('g'));
        self::assertNull($scope->depthOfVariable('missing'));
    }

    public function testInnermostBindingWins(): void
    {
        $scope = Scope::variable(Scope::variable(null, 'x'), 'x');

        self::assertSame(0, $scope->depthOfVariable('x'));
    }

    public function testLabelsLiveInTheirOwnNamespace(): void
    {
        $scope = Scope::variable(Scope::label(null, 'out'), 'out');

        self::assertSame(1, $scope->depthOfLabel('out'));
        self::assertSame(0, $scope->depthOfVariable('out'));
        self::assertNull($scope->depthOfLabel('missing'));
    }

    public function testFunctionEntriesCarryTheirSignature(): void
    {
        $info  = new FuncInfo(new FuncDef('f', ['a', 'b'], new Identity()), null, 0);
        $scope = Scope::func(null, $info);

        self::assertSame(ScopeKindEnum::Func, $scope->kind);
        self::assertSame('f', $scope->name);
        self::assertSame(2, $scope->arity);
        self::assertSame($info, $scope->function);
        self::assertSame(ScopeKindEnum::Param, Scope::param(null, 'p')->kind);
        self::assertSame(ScopeKindEnum::Variable, Scope::variable(null, 'v')->kind);
        self::assertSame(ScopeKindEnum::Label, Scope::label(null, 'l')->kind);
    }
}
