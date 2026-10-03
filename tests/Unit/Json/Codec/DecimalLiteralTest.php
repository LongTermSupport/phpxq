<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\Codec\DecimalLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DecimalLiteralTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function compareProvider(): iterable
    {
        yield 'equal'                       => ['1', '1', 0];
        yield 'equal across scale'          => ['1.000', '1', 0];
        yield 'exponent form equal'         => ['1E+2', '100', 0];
        yield 'less'                        => ['1', '2', -1];
        yield 'greater'                     => ['2.5', '2.49', 1];
        yield 'negative'                    => ['-1', '1', -1];
        yield 'both negative'               => ['-2', '-1', -1];
        yield 'zero vs negative zero'       => ['0', '-0.0', 0];
        yield 'zero vs positive'            => ['0.00', '0.001', -1];
        yield 'adjacent big integers'       => ['13911860366432393', '13911860366432392', 1];
        yield 'huge exponent'               => ['1E+999999999', '9E+999999998', 1];
        yield 'tiny exponent'               => ['1E-999999999', '1E-999999998', -1];
        yield 'long fractions'              => ['0.12345678901234567890123456789', '0.12345678901234567890123456788', 1];
        yield 'zero vs tiny'                => ['0', '1E-999999999', -1];
        yield 'negative tiny vs zero'       => ['-1E-999999999', '0', -1];
        yield 'different lengths same lead' => ['1.5', '1.50001', -1];
    }

    #[DataProvider('compareProvider')]
    public function testCompare(string $left, string $right, int $expected): void
    {
        self::assertSame($expected, DecimalLiteral::compare($left, $right));
        self::assertSame(-$expected, DecimalLiteral::compare($right, $left));
    }

    public function testCanonicalForms(): void
    {
        self::assertSame('1.00', DecimalLiteral::canonical(false, '100', -2));
        self::assertSame('1E+2', DecimalLiteral::canonical(false, '1', 2));
        self::assertSame('0.00001', DecimalLiteral::canonical(false, '1', -5));
        self::assertSame('0.000001', DecimalLiteral::canonical(false, '1', -6));
        self::assertSame('1E-7', DecimalLiteral::canonical(false, '1', -7));
        self::assertSame('-12.5', DecimalLiteral::canonical(true, '125', -1));
        self::assertSame('0', DecimalLiteral::canonical(false, '0', 0));
        self::assertSame('0.00', DecimalLiteral::canonical(false, '0', -2));
        self::assertSame('0E+3', DecimalLiteral::canonical(false, '0', 3));
        self::assertSame('1.23E+3', DecimalLiteral::canonical(false, '123', 1));
        self::assertSame('12300', DecimalLiteral::canonical(false, '12300', 0));
        self::assertSame('0.1', DecimalLiteral::canonical(false, '1', -1));
    }
}
