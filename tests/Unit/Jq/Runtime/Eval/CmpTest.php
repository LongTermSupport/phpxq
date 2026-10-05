<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Cmp;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Cmp::class)]
final class CmpTest extends TestCase
{
    #[DataProvider('comparisons')]
    public function testOperators(mixed $left, mixed $right, string $operator, bool $expected): void
    {
        $actual = match ($operator) {
            'eq'    => Cmp::eq($left, $right),
            'ne'    => Cmp::ne($left, $right),
            'lt'    => Cmp::lt($left, $right),
            'le'    => Cmp::le($left, $right),
            'gt'    => Cmp::gt($left, $right),
            'ge'    => Cmp::ge($left, $right),
            default => self::fail('unknown operator ' . $operator),
        };

        self::assertSame($expected, $actual);
    }

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
        yield 'float lt equal'    => [1.5, 1.5, 'lt', false];
        yield 'string lt equal'   => ['a', 'a', 'lt', false];
        yield 'float gt equal'    => [1.5, 1.5, 'gt', false];
        yield 'string gt equal'   => ['a', 'a', 'gt', false];
        yield 'float le equal'    => [1.5, 1.5, 'le', true];
        yield 'float ge equal'    => [1.5, 1.5, 'ge', true];
        yield 'int le string'     => [10, '9', 'le', true];
        yield 'string ge int'     => ['9', 10, 'ge', true];
        yield 'int lt string'     => [10, '9', 'lt', true];
        yield 'string gt int'     => ['9', 10, 'gt', true];
        yield 'nan le nan'        => [\NAN, \NAN, 'le', true];
        yield 'nan le int'        => [\NAN, 1, 'le', true];
        yield 'int ge nan'        => [1, \NAN, 'ge', true];
        yield 'nan ge nan'        => [\NAN, \NAN, 'ge', false];
        yield 'nan ge int'        => [\NAN, 1, 'ge', false];
        yield 'int le nan'        => [1, \NAN, 'le', false];
        yield 'int and float le'  => [2, 2.5, 'le', true];
        yield 'float and int ge'  => [2.5, 2, 'ge', true];
        yield 'int ne string'     => [1, '1', 'ne', true];
        yield 'float ge nan'      => [1.5, \NAN, 'ge', true];
        yield 'float le nan'      => [1.5, \NAN, 'le', false];
        yield 'nan le float'      => [\NAN, 1.5, 'le', true];
    }
}
