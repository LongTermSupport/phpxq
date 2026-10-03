<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\NotOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(NotOp::class)]
final class NotOpTest extends OpTestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function inputs(): iterable
    {
        yield 'null'    => [null, true];
        yield 'false'   => [false, true];
        yield 'true'    => [true, false];
        yield 'zero'    => [0, false];
        yield 'string'  => ['', false];
        yield 'array'   => [[], false];
    }

    #[DataProvider('inputs')]
    public function testNegatesTruthiness(mixed $input, bool $expected): void
    {
        self::assertSame([$expected], self::outputs(new NotOp(), $input));
    }
}
