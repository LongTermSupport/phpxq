<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IterateOp::class)]
final class IterateOpTest extends OpTestCase
{
    public function testIteratesTheInputWithoutATarget(): void
    {
        self::assertSame([1, 2], self::outputs(new IterateOp(null), [1, 2]));
        self::assertSame([1, 2], self::outputs(new IterateOp(null), self::object(['a' => 1, 'b' => 2])));
    }

    public function testIteratesEveryTargetOutput(): void
    {
        $op = new IterateOp(self::generator([[1], [2, 3]]));

        self::assertSame([1, 2, 3], self::outputs($op));
    }

    public function testRejectsScalars(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Cannot iterate over number (123)');

        self::outputs(new IterateOp(null), 123);
    }

    public function testRejectsNull(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Cannot iterate over null (null)');

        self::outputs(new IterateOp(null), null);
    }

    public function testPathModeReportsIndexesAndKeys(): void
    {
        self::assertSame([[[0], 'a'], [[1], 'b']], self::pathOutputs(new IterateOp(null), ['a', 'b']));
        self::assertSame([[['a'], 1]], self::pathOutputs(new IterateOp(null), self::object(['a' => 1])));
        self::assertSame([[['x', 0], 'a']], self::pathOutputs(new IterateOp(null), ['a'], ['x']));
    }

    public function testPathModeRejectsAComputedValue(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Invalid path expression near attempt to iterate through [1]');

        self::pathOutputs(new IterateOp(self::constant([1])));
    }

    public function testPathModeRejectsNonIterableValues(): void
    {
        $this->expectException(JqException::class);

        self::pathOutputs(new IterateOp(null), 5);
    }
}
