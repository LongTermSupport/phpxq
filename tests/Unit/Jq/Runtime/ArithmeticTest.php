<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Arithmetic::class)]
final class ArithmeticTest extends TestCase
{
    use AssertsRaised;

    public function testNormalizeKeepsIntegralValuesAsInts(): void
    {
        self::assertSame(3, Arithmetic::normalize(3.0));
        self::assertSame(-7, Arithmetic::normalize(-7.0));
        self::assertSame(9007199254740992, Arithmetic::normalize(9007199254740992.0));
        self::assertSame(1.5, Arithmetic::normalize(1.5));
        self::assertSame(1.8014398509481984E16, Arithmetic::normalize(1.8014398509481984E16));
    }

    public function testNormalizeKeepsNegativeZero(): void
    {
        $zero = Arithmetic::normalize(-0.0);

        self::assertIsFloat($zero);
        self::assertLessThan(0, fdiv(1.0, $zero));
        self::assertSame(0, Arithmetic::normalize(0.0));
    }

    public function testNormalizeLeavesNonFiniteValuesAlone(): void
    {
        self::assertSame(\INF, Arithmetic::normalize(\INF));
        self::assertNan(Arithmetic::normalize(\NAN));
    }

    #[DataProvider('additions')]
    public function testAdd(mixed $left, mixed $right, mixed $expected): void
    {
        self::assertEquals($expected, Arithmetic::add($left, $right));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function additions(): iterable
    {
        yield 'ints'              => [1, 2, 3];
        yield 'floats'            => [0.5, 0.25, 0.75];
        yield 'float sums to int' => [0.5, 0.5, 1];
        yield 'null left'         => [null, 5, 5];
        yield 'null right'        => ['a', null, 'a'];
        yield 'null and null'     => [null, null, null];
        yield 'strings'           => ['a', 'b', 'ab'];
        yield 'arrays'            => [[1], [2, 3], [1, 2, 3]];
        yield 'objects'           => [new JsonObject(['a' => 1, 'b' => 2]), new JsonObject(['b' => 3, 'c' => 4]), new JsonObject(['a' => 1, 'b' => 3, 'c' => 4])];
        yield 'beyond 2^53'       => [9007199254740992, 1, 9007199254740993.0];
        yield 'precise number'    => [new PreciseNumber(1.0E+1000, '1E+1000'), 0, \INF];
    }

    public function testAddKeepsALoneNullOperandsPreciseLiteral(): void
    {
        $precise = new PreciseNumber(1.0E+1000, '1E+1000');

        self::assertSame($precise, Arithmetic::add(null, $precise));
        self::assertSame($precise, Arithmetic::add($precise, null));
    }

    public function testAddKeepsObjectKeyPositions(): void
    {
        $sum = Arithmetic::add(new JsonObject(['a' => 1, 'b' => 2]), new JsonObject(['a' => 9]));

        self::assertInstanceOf(JsonObject::class, $sum);
        self::assertSame(['a', 'b'], $sum->keys());
        self::assertSame(9, $sum->get('a'));
    }

    #[DataProvider('subtractions')]
    public function testSubtract(mixed $left, mixed $right, mixed $expected): void
    {
        self::assertSame($expected, Arithmetic::subtract($left, $right));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function subtractions(): iterable
    {
        yield 'ints'            => [5, 3, 2];
        yield 'floats'          => [1.5, 0.5, 1];
        yield 'arrays'          => [[1, 2, 3, 2], [2], [1, 3]];
        yield 'arrays by value' => [[[1], [2]], [[1]], [[2]]];
        yield 'array by empty'  => [[1], [], [1]];
    }

    #[DataProvider('multiplications')]
    public function testMultiply(mixed $left, mixed $right, mixed $expected): void
    {
        self::assertSame($expected, Arithmetic::multiply($left, $right));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function multiplications(): iterable
    {
        yield 'ints'               => [3, 4, 12];
        yield 'floats'             => [0.5, 4, 2];
        yield 'overflow to float'  => [4503599627370496, 4503599627370496, 2.028240960365167E31];
        yield 'string repeat'      => ['ab', 3, 'ababab'];
        yield 'number first'       => [3, 'ab', 'ababab'];
        yield 'zero repeats'       => ['abc', 0, ''];
        yield 'fraction below one' => ['abc', 0.5, ''];
        yield 'fraction floors'    => ['abc', 1.5, 'abc'];
        yield 'negative'           => ['abc', -1, null];
        yield 'negative fraction'  => ['abc', -0.5, null];
        yield 'nan'                => ['abc', \NAN, null];
        yield 'empty string'       => ['', 1000000000, ''];
    }

    public function testMultiplyMergesObjectsRecursively(): void
    {
        $left  = new JsonObject(['a' => new JsonObject(['b' => 1, 'c' => 2]), 'd' => 1]);
        $right = new JsonObject(['a' => new JsonObject(['b' => 5]), 'd' => new JsonObject(['x' => 1])]);

        self::assertEquals(
            new JsonObject(['a' => new JsonObject(['b' => 5, 'c' => 2]), 'd' => new JsonObject(['x' => 1])]),
            Arithmetic::multiply($left, $right),
        );
    }

    public function testMultiplyRejectsHugeRepeats(): void
    {
        self::assertRaises(JqException::class, 'Repeat string result too long', static fn (): mixed => Arithmetic::multiply('abc', 1000000000));
    }

    #[DataProvider('divisions')]
    public function testDivide(mixed $left, mixed $right, mixed $expected): void
    {
        self::assertSame($expected, Arithmetic::divide($left, $right));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function divisions(): iterable
    {
        yield 'exact'          => [10, 4, 2.5];
        yield 'to int'         => [10, 5, 2];
        yield 'split'          => ['a, b,c', ', ', ['a', 'b,c']];
        yield 'split empty'    => ['', ',', []];
        yield 'split chars'    => ['aé', '', ['a', 'é']];
        yield 'no separator'   => ['abc', ',', ['abc']];
        yield 'adjacent'       => ['a,,b', ',', ['a', '', 'b']];
    }

    #[DataProvider('remainders')]
    public function testModulo(mixed $left, mixed $right, mixed $expected): void
    {
        self::assertSame($expected, Arithmetic::modulo($left, $right));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function remainders(): iterable
    {
        yield 'positive'            => [5, 3, 2];
        yield 'negative dividend'   => [-5, 3, -2];
        yield 'negative divisor'    => [5, -3, 2];
        yield 'exact'               => [6, 3, 0];
        yield 'fractions truncate'  => [5.9, 3.9, 2];
        yield 'divisor minus one'   => [7, -1, 0];
        yield 'huge divisor'        => [5, 1.0E300, 5];
    }

    public function testModuloWithNanIsNan(): void
    {
        self::assertNan(Arithmetic::modulo(\NAN, 3));
        self::assertNan(Arithmetic::modulo(3, \NAN));
    }

    public function testNegate(): void
    {
        self::assertSame(-3, Arithmetic::negate(3));
        self::assertSame(2.5, Arithmetic::negate(-2.5));
        $zero = Arithmetic::negate(0);
        self::assertIsFloat($zero);
        self::assertSame(0.0, abs($zero));
        self::assertLessThan(0, fdiv(1.0, $zero));
    }

    public function testNegatePreservesPreciseLiterals(): void
    {
        $negated = Arithmetic::negate(new PreciseNumber(1.3911860366432393E16, '13911860366432393'));

        self::assertInstanceOf(PreciseNumber::class, $negated);
        self::assertSame('-13911860366432393', $negated->literal);

        $back = Arithmetic::negate($negated);

        self::assertInstanceOf(PreciseNumber::class, $back);
        self::assertSame('13911860366432393', $back->literal);
    }

    /**
     * @param callable(): mixed $operation
     */
    #[DataProvider('errors')]
    public function testTypeErrors(string $name, callable $operation, string $message): void
    {
        self::assertRaises(JqException::class, $message, static fn (): mixed => $operation());
    }

    /**
     * @return iterable<string, array{string, callable(): mixed, string}>
     */
    public static function errors(): iterable
    {
        yield 'add'         => ['add', static fn (): mixed => Arithmetic::add(1, 'a'), 'number (1) and string ("a") cannot be added'];
        yield 'add object'  => ['add', static fn (): mixed => Arithmetic::add('a', new JsonObject(['b' => 1])), 'string ("a") and object ({"b":1}) cannot be added'];
        yield 'subtract'    => ['sub', static fn (): mixed => Arithmetic::subtract('a', 'b'), 'string ("a") and string ("b") cannot be subtracted'];
        yield 'multiply'    => ['mul', static fn (): mixed => Arithmetic::multiply([1], 2), 'array ([1]) and number (2) cannot be multiplied'];
        yield 'divide'      => ['div', static fn (): mixed => Arithmetic::divide('a', 1), 'string ("a") and number (1) cannot be divided'];
        yield 'divide zero' => ['div', static fn (): mixed => Arithmetic::divide(1, 0), 'number (1) and number (0) cannot be divided because the divisor is zero'];
        yield 'divide 0.0'  => ['div', static fn (): mixed => Arithmetic::divide(0, 0.0), 'number (0) and number (0) cannot be divided because the divisor is zero'];
        yield 'modulo'      => ['mod', static fn (): mixed => Arithmetic::modulo('a', 1), 'string ("a") and number (1) cannot be divided'];
        yield 'modulo zero' => ['mod', static fn (): mixed => Arithmetic::modulo(1, 0), 'number (1) and number (0) cannot be divided (remainder) because the divisor is zero'];
        yield 'modulo tiny' => ['mod', static fn (): mixed => Arithmetic::modulo(1, 0.5), 'number (1) and number (0.5) cannot be divided (remainder) because the divisor is zero'];
        yield 'negate'      => ['neg', static fn (): mixed => Arithmetic::negate('foo'), 'string ("foo") cannot be negated'];
    }

    public function testSplit(): void
    {
        self::assertSame(['a', 'b'], Arithmetic::split('a,b', ','));
        self::assertSame([], Arithmetic::split('', ''));
    }
}
