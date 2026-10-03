<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FieldOp::class)]
final class FieldOpTest extends OpTestCase
{
    public function testReadsAMember(): void
    {
        $op = new FieldOp('a');

        self::assertSame([1], self::outputs($op, self::object(['a' => 1])));
        self::assertSame([null], self::outputs($op, self::object(['b' => 1])));
        self::assertSame([null], self::outputs($op, null));
        self::assertSame(1, $op->value(null, self::object(['a' => 1])));
    }

    public function testRejectsOtherTypes(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Cannot index array with string ("a")');

        self::outputs(new FieldOp('a'), [1]);
    }

    public function testPathModeExtendsThePath(): void
    {
        self::assertSame([[['x', 'a'], 1]], self::pathOutputs(new FieldOp('a'), self::object(['a' => 1]), ['x']));
    }

    public function testPathModeRejectsAComputedValue(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Invalid path expression near attempt to access element "a" of {"a":1}');

        self::pathOutputs(new FieldOp('a'), self::object(['a' => 1]), null);
    }
}
