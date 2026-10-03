<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\CompiledJq;
use LTS\PhpXq\Jq\Runtime\Eval\GlobalVarOp;
use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledJq::class)]
final class CompiledJqTest extends TestCase
{
    public function testInstallsTheContextForTheDurationOfTheRun(): void
    {
        $state   = new RunState();
        $program = new CompiledJq(new GlobalVarOp($state, 'x'), $state);
        $seen    = [];

        $program->run(new StubContext(['x' => 'value'], []), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame(['value'], $seen);
        self::assertNull($state->global('x'));
    }

    public function testRestoresThePreviousContextEvenWhenTheRunFails(): void
    {
        $state   = new RunState();
        $program = new CompiledJq(new GlobalVarOp($state, 'x'), $state);
        $state->enter(new StubContext(['x' => 'outer'], []), ['x' => 'outer']);

        try {
            $program->run(new StubContext(['x' => 'inner'], []), null, static function (): void {
                throw new JqException('stop');
            });
            self::fail('expected an error');
        } catch (JqException) {
            self::assertSame('outer', $state->global('x'));
        }
    }
}
