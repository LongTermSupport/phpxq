<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\SingleLogicOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(SingleLogicOp::class)]
final class SingleLogicOpTest extends OpTestCase
{
    /**
     * @return iterable<string, array{mixed, mixed, bool, bool}>
     */
    public static function truthTable(): iterable
    {
        yield 'true and true'   => [true, true, true, true];
        yield 'true and null'   => [true, null, true, false];
        yield 'false and true'  => [false, true, true, false];
        yield 'zero is truthy'  => [0, '', true, true];
        yield 'false or true'   => [false, true, false, true];
        yield 'null or false'   => [null, false, false, false];
        yield 'true or false'   => [true, false, false, true];
    }

    #[DataProvider('truthTable')]
    public function testTruthTable(mixed $left, mixed $right, bool $isAnd, bool $expected): void
    {
        $op = new SingleLogicOp(self::constant($left), self::constant($right), $isAnd);

        self::assertSame($expected, $op->value(null, null));
        self::assertSame([$expected], self::outputs($op));
    }
}
