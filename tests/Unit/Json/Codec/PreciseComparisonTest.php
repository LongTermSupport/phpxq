<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\NumberParser;
use LTS\PhpXq\Json\Values;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two preserved literals compare exactly (jq with decNumber); everything else compares as doubles.
 *
 * @internal
 */
final class PreciseComparisonTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function literalPairs(): iterable
    {
        yield 'adjacent big integers'  => ['13911860366432393', '13911860366432392', 1];
        yield 'equal big integers'     => ['13911860366432393', '13911860366432393', 0];
        yield 'long fractions'         => ['0.12345678901234567890123456789', '0.12345678901234567890123456788', 1];
        yield 'same value other scale' => ['1.000', '1.0', 0];
        yield 'exponent form'          => ['1e2', '100.0', 0];
        yield 'huge exponents'         => ['1E+999999999', '9E+999999998', 1];
        yield 'both beyond 2^53'       => ['9007199254740995', '9007199254740993', 1];
    }

    #[DataProvider('literalPairs')]
    public function testPreservedLiteralsCompareExactly(string $left, string $right, int $expected): void
    {
        $a = NumberParser::parse($left);
        $b = NumberParser::parse($right);

        self::assertSame($expected, Values::compare($a, $b));
        self::assertSame(-$expected, Values::compare($b, $a));
        self::assertSame(0 === $expected, Values::equals($a, $b));
    }

    public function testPreservedLiteralAgainstComputedDoubleUsesDoubles(): void
    {
        $literal = NumberParser::parse('9007199254740993');

        self::assertSame(0, Values::compare($literal, 9007199254740992.0));
        self::assertSame(0, Values::compare(1.0, NumberParser::parse('1.000')));
        self::assertSame(0, Values::compare(1, NumberParser::parse('1.000')));
    }
}
