<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleObjectOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SingleObjectOp::class)]
final class SingleObjectOpTest extends OpTestCase
{
    public function testBuildsTheObject(): void
    {
        $op = new SingleObjectOp([
            [self::constant('a'), new FieldOp('x')],
            [self::constant('b'), self::constant(2)],
        ]);

        self::assertEquals([self::object(['a' => 1, 'b' => 2])], self::outputs($op, self::object(['x' => 1])));
    }

    public function testEmptyObject(): void
    {
        self::assertEquals([self::object([])], self::outputs(new SingleObjectOp([])));
    }

    public function testNumericLookingKeysStayStrings(): void
    {
        $result = new SingleObjectOp([[self::constant('1'), self::constant('x')]])->value(null, null);

        self::assertSame(['1'], $result->keys());
    }

    public function testRejectsNonStringKeys(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Object keys must be strings');

        self::outputs(new SingleObjectOp([[self::constant(null), self::constant(1)]]));
    }
}
