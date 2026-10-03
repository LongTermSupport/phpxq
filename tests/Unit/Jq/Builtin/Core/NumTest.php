<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\Num;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NumTest extends TestCase
{
    #[DataProvider('normalised')]
    public function testOf(int|float $input, int|float $expected): void
    {
        self::assertSame($expected, Num::of($input));
    }

    /**
     * @return iterable<string, array{int|float, int|float}>
     */
    public static function normalised(): iterable
    {
        yield 'integral float becomes int' => [3.0, 3];
        yield 'int stays' => [7, 7];
        yield 'fraction stays' => [1.5, 1.5];
        yield 'limit of exact ints' => [9007199254740992.0, 9007199254740992];
        yield 'beyond 2^53 stays float' => [9007199254740994.0, 9007199254740994.0];
        yield 'negative integral' => [-4.0, -4];
        yield 'huge float' => [1.0e300, 1.0e300];
        yield 'infinity' => [\INF, \INF];
    }

    public function testNegativeZeroStaysAFloat(): void
    {
        $result = Num::of(-0.0);

        self::assertIsFloat($result);
        self::assertSame(-\INF, fdiv(1.0, $result));
    }

    public function testPositiveZeroBecomesAnInt(): void
    {
        self::assertSame(0, Num::of(0.0));
    }

    public function testNanStaysNan(): void
    {
        self::assertNan(Num::of(\NAN));
    }

    #[DataProvider('candidates')]
    public function testIsNumber(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Num::isNumber($value));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'int' => [1, true];
        yield 'float' => [1.5, true];
        yield 'precise number' => [new PreciseNumber(1.0e20, '100000000000000000000'), true];
        yield 'numeric string' => ['1', false];
        yield 'null' => [null, false];
        yield 'boolean' => [true, false];
        yield 'array' => [[1], false];
    }

    public function testToFloat(): void
    {
        self::assertSame(2.0, Num::toFloat(2));
        self::assertSame(1.5, Num::toFloat(1.5));
        self::assertSame(1.0e20, Num::toFloat(new PreciseNumber(1.0e20, '100000000000000000000')));
    }

    public function testToInt(): void
    {
        self::assertSame(1, Num::toInt(1.9));
        self::assertSame(-1, Num::toInt(-1.9));
        self::assertSame(0, Num::toInt(\NAN));
        self::assertSame(\PHP_INT_MAX, Num::toInt(\INF));
        self::assertSame(\PHP_INT_MIN, Num::toInt(-\INF));
        self::assertSame(\PHP_INT_MAX, Num::toInt(1.0e30));
    }
}
