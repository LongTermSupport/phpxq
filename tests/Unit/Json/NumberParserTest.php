<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\NumberParser;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NumberParserTest extends TestCase
{
    #[DataProvider('plainProvider')]
    public function testPlainNumbers(string $literal, int|float $expected): void
    {
        $parsed = NumberParser::parse($literal);

        self::assertSame($expected, $parsed);
    }

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function plainProvider(): iterable
    {
        yield 'zero'                 => ['0', 0];
        yield 'integer'              => ['123', 123];
        yield 'negative integer'     => ['-45', -45];
        yield 'leading zeros'        => ['007', 7];
        yield 'plus sign'            => ['+5', 5];
        yield 'two to the 53'        => ['9007199254740992', 9007199254740992];
        yield 'negative two to 53'   => ['-9007199254740992', -9007199254740992];
        yield 'fraction'             => ['1.5', 1.5];
        yield 'negative fraction'    => ['-0.25', -0.25];
        yield 'bare leading point'   => ['.5', 0.5];
        yield 'seventeen digits'     => ['0.30000000000000004', 0.30000000000000004];
        yield 'fraction below one'   => ['0.0001', 0.0001];
        yield 'explicit plus exponent' => ['1E+0', 1];
        yield 'trailing point'       => ['5.', 5];
        yield 'exponent cancels'     => ['25e-1', 2.5];
    }

    public function testNegativeZeroIsAFloat(): void
    {
        $parsed = NumberParser::parse('-0');

        self::assertIsFloat($parsed);
        self::assertSame(-\INF, fdiv(1.0, $parsed));
    }

    #[DataProvider('preciseProvider')]
    public function testPreservedLiterals(string $literal, string $canonical, float $value): void
    {
        $parsed = NumberParser::parse($literal);

        self::assertInstanceOf(PreciseNumber::class, $parsed);
        self::assertSame($canonical, $parsed->literal);
        self::assertSame($value, $parsed->value);
    }

    /**
     * @return iterable<string, array{string, string, float}>
     */
    public static function preciseProvider(): iterable
    {
        yield 'trailing zeros'          => ['1.000', '1.000', 1.0];
        yield 'one point zero'          => ['1.0', '1.0', 1.0];
        yield 'negative exponent'       => ['100e-2', '1.00', 1.0];
        yield 'zero point zero'         => ['0.0', '0.0', 0.0];
        yield 'exponent form of int'    => ['1e2', '1E+2', 100.0];
        yield 'mantissa exponent'       => ['1.5e3', '1.5E+3', 1500.0];
        yield 'small exponent form'     => ['1e-5', '0.00001', 1.0e-5];
        yield 'smaller than plain'      => ['0.00001', '0.00001', 1.0e-5];
        yield 'very small'              => ['1e-7', '1E-7', 1.0e-7];
        yield 'long fraction'           => ['0.12345678901234567890123456789', '0.12345678901234567890123456789', 0.12345678901234568];
        yield 'above two to 53'         => ['9007199254740993', '9007199254740993', 9007199254740992.0];
        yield 'negative above 2^53'     => ['-9007199254740993', '-9007199254740993', -9007199254740992.0];
        yield 'exactly representable'   => ['13911860366432392', '13911860366432392', 13911860366432392.0];
        yield 'big integer'             => ['12345678909876543212345', '12345678909876543212345', 1.2345678909876543e22];
        yield 'ten to the sixteen'      => ['10000000000000000', '10000000000000000', 1.0e16];
        yield 'overflow'                => ['1E+1000', '1E+1000', \INF];
        yield 'negative overflow'       => ['-1E+1000', '-1E+1000', -\INF];
        yield 'max exponent'            => ['9E999999999', '9E+999999999', \INF];
        yield 'min exponent'            => ['1E-999999999', '1E-999999999', 0.0];
        yield 'scaled coefficient'      => ['0.000000001E-999999990', '1E-999999999', 0.0];
        yield 'long coefficient'        => ['9999999999E999999990', '9.999999999E+999999999', \INF];
        yield 'zero with exponent'      => ['0e5', '0E+5', 0.0];
        yield 'negative zero fraction'  => ['-0.0', '-0.0', -0.0];
        yield 'leading zeros kept off'  => ['00012.50', '12.50', 12.5];
    }

    public function testNonFiniteWords(): void
    {
        self::assertNan(NumberParser::parse('nan'));
        self::assertNan(NumberParser::parse('NaN'));
        self::assertNan(NumberParser::parse('-nan'));
        self::assertSame(\INF, NumberParser::parse('Infinity'));
        self::assertSame(-\INF, NumberParser::parse('-Infinity'));
        self::assertSame(\INF, NumberParser::parse('infinity'));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidLiteralsAreRejected(string $literal): void
    {
        self::assertNull(NumberParser::tryParse($literal));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty'         => [''];
        yield 'letters'       => ['abc'];
        yield 'dangling e'    => ['1e'];
        yield 'dangling sign' => ['1e+'];
        yield 'two points'    => ['1.5.5'];
        yield 'lone minus'    => ['-'];
        yield 'lone plus'     => ['+'];
        yield 'double minus'  => ['--1'];
        yield 'trailing junk' => ['123abc'];
        yield 'nan payload'   => ['NaN1'];
        yield 'hex'           => ['0x10'];
        yield 'only point'    => ['.'];
    }

    public function testParseThrowsForInvalidLiteral(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NumberParser::parse('1e');
    }
}
