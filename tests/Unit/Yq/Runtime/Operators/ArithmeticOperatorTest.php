<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\Operators\ArithmeticOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ArithmeticOperator::class)]
final class ArithmeticOperatorTest extends TestCase
{
    #[DataProvider('programs')]
    public function testComputes(string $expression, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, nullInput: true));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function programs(): iterable
    {
        yield 'int add' => ['1 + 2', "3\n"];
        yield 'int subtract' => ['5 - 7', "-2\n"];
        yield 'int multiply' => ['3 * 4', "12\n"];
        yield 'int divide' => ['6 / 3', "2\n"];
        yield 'float add' => ['1.5 + 1', "2.5\n"];
        yield 'modulo' => ['7 % 4', "3\n"];
        yield 'string concat' => ['"a" + "b"', "ab\n"];
        yield 'string repeat' => ['"ab" * 3', "ababab\n"];
        yield 'sequence concat' => ['[1] + [2]', "- 1\n- 2\n"];
        yield 'map merge via add' => ['{"a": 1} + {"b": 2}', "a: 1\nb: 2\n"];
        yield 'null plus value' => ['null + 3', "3\n"];
        yield 'value plus null' => ['3 + null', "3\n"];
        yield 'deep merge' => ['{"a": {"b": 1}} * {"a": {"c": 2}}', "a:\n  b: 1\n  c: 2\n"];
        yield 'duration add' => ['"2001-12-15T02:59:43Z" + "1h"', "2001-12-15T03:59:43Z\n"];
        yield 'date subtract' => ['"2001-12-15T02:59:43Z" - "1h"', "2001-12-15T01:59:43Z\n"];
    }

    public function testDivisionByZeroIsInfinity(): void
    {
        self::assertSame("+Inf\n", YqHarness::run('1 / 0', nullInput: true));
    }

    public function testMismatchedTypesAreAnError(): void
    {
        $this->expectException(Throwable::class);
        YqHarness::run('{"a": 1} - 1', nullInput: true);
    }
}
