<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Cmp;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cmp::class)]
final class CmpTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'int eq'            => [1, 1, 'eq', true];
        yield 'int ne'            => [1, 2, 'ne', true];
        yield 'int lt'            => [1, 2, 'lt', true];
        yield 'int le equal'      => [2, 2, 'le', true];
        yield 'int gt'            => [3, 2, 'gt', true];
        yield 'int ge less'       => [1, 2, 'ge', false];
        yield 'int and float eq'  => [1, 1.0, 'eq', true];
        yield 'string eq'         => ['a', 'a', 'eq', true];
        yield 'numeric strings'   => ['10', '9', 'lt', true];
        yield 'type order'        => [null, false, 'lt', true];
        yield 'array vs object'   => [[], new JsonObject([]), 'lt', true];
        yield 'nan sorts lowest'  => [\NAN, 1, 'lt', true];
        yield 'nan is not equal'  => [\NAN, \NAN, 'eq', false];
        yield 'nan ne nan'        => [\NAN, \NAN, 'ne', true];
        yield 'object equality'   => [new JsonObject(['a' => 1]), new JsonObject(['a' => 1]), 'eq', true];
        yield 'array ordering'    => [[1, 2], [1, 3], 'lt', true];
    }

    #[DataProvider('comparisons')]
    public function testOperators(mixed $left, mixed $right, string $operator, bool $expected): void
    {
        self::assertSame($expected, Cmp::$operator($left, $right));
    }
}
