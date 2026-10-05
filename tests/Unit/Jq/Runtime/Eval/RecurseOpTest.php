<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\RecurseOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(RecurseOp::class)]
final class RecurseOpTest extends OpTestCase
{
    public function testWalksDepthFirst(): void
    {
        $input = [1, [2], self::object(['a' => 3])];

        self::assertEquals([$input, 1, [2], 2, self::object(['a' => 3]), 3], self::outputs(new RecurseOp(), $input));
    }

    public function testScalarsEmitThemselves(): void
    {
        self::assertSame(['x'], self::outputs(new RecurseOp(), 'x'));
        self::assertSame([null], self::outputs(new RecurseOp()));
    }

    public function testPathModeReportsEveryPath(): void
    {
        $outputs = self::pathOutputs(new RecurseOp(), [self::object(['a' => 1])]);

        self::assertSame([[], [0], [0, 'a']], array_column($outputs, 0));
    }

    public function testPathModeExtendsTheIncomingPath(): void
    {
        $outputs = self::pathOutputs(new RecurseOp(), [5], ['x']);

        self::assertSame([['x'], ['x', 0]], array_column($outputs, 0));
    }

    public function testPathModeWithoutAPathCannotDescend(): void
    {
        $this->expectException(JqException::class);

        self::pathOutputs(new RecurseOp(), [5], null);
    }

    public function testWalkedPathsAreListsEvenWhenTheStartingPathHasKeys(): void
    {
        $seen   = [];
        $method = new ReflectionMethod(RecurseOp::class, 'walkPaths');
        $method->invokeArgs(null, [[7], static function (?array $path, mixed $value) use (&$seen): void {
            $seen[] = [$path, $value];
        }, 'start' => 'p']);

        self::assertSame([[['p'], [7]], [['p', 0], 7]], $seen);
    }

    public function testPathModeWithoutAPathOnAScalarEmitsIt(): void
    {
        self::assertSame([[null, 5]], self::pathOutputs(new RecurseOp(), 5, null));
    }
}
