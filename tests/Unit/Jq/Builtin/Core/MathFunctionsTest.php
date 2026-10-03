<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\MathFunctions;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MathFunctionsTest extends TestCase
{
    #[DataProvider('unary')]
    public function testUnary(string $name, int|float $input, int|float $expected): void
    {
        self::assertEqualsWithDelta($expected, Harness::call($name, $input), 1e-12, $name);
    }

    /**
     * @return iterable<string, array{string, int|float, int|float}>
     */
    public static function unary(): iterable
    {
        yield 'floor' => ['floor', -1.1, -2];
        yield 'floor integral' => ['floor', 3.0, 3];
        yield 'ceil' => ['ceil', 1.2, 2];
        yield 'round half away' => ['round', 2.5, 3];
        yield 'round negative half' => ['round', -2.5, -3];
        yield 'trunc negative' => ['trunc', -1.7, -1];
        yield 'trunc positive' => ['trunc', 1.7, 1];
        yield 'nearbyint ties to even' => ['nearbyint', 2.5, 2];
        yield 'rint ties to even' => ['rint', 3.5, 4];
        yield 'sqrt' => ['sqrt', 9, 3];
        yield 'fabs' => ['fabs', -10, 10];
        yield 'fabs negative zero' => ['fabs', -0.0, 0];
        yield 'cbrt perfect cube' => ['cbrt', 27, 3];
        yield 'cbrt negative' => ['cbrt', -8, -2];
        yield 'cbrt' => ['cbrt', 2, 1.2599210498948732];
        yield 'exp2' => ['exp2', 10, 1024];
        yield 'exp10' => ['exp10', 3, 1000];
        yield 'pow10' => ['pow10', 2, 100];
        yield 'log2 of a power of two is exact' => ['log2', 8, 3];
        yield 'log2 of a half' => ['log2', 0.5, -1];
        yield 'log10' => ['log10', 1000, 3];
        yield 'log' => ['log', 1, 0];
        yield 'exp' => ['exp', 0, 1];
        yield 'sin' => ['sin', 0, 0];
        yield 'cos' => ['cos', 0, 1];
        yield 'significand' => ['significand', 10, 1.25];
        yield 'significand of a power of two' => ['significand', 8, 1];
        yield 'logb' => ['logb', 8, 3];
        yield 'logb of a fraction' => ['logb', 0.1, -4];
        yield 'expm1' => ['expm1', 0, 0];
        yield 'log1p' => ['log1p', 0, 0];
    }

    public function testUnaryResultsAreIntsWhenIntegral(): void
    {
        self::assertSame(3, Harness::call('floor', 3.7));
        self::assertSame(3, Harness::call('sqrt', 9));
        self::assertSame(1.5, Harness::call('abs', 1.5));
    }

    public function testComputationConvertsPreciseNumbersToDoubles(): void
    {
        self::assertSame(13911860366432392.0, Harness::call('floor', Harness::json('13911860366432393')));
    }

    public function testNonNumbersAreRejected(): void
    {
        self::assertSame('string ("a") number required', Harness::error('floor', 'a'));
        self::assertSame('null (null) number required', Harness::error('sqrt', null));
        self::assertSame('string ("a") number required', Harness::error('pow', null, ['a', 2]));
    }

    public function testSpecialValues(): void
    {
        self::assertNan(Harness::call('sqrt', -1));
        self::assertNan(Harness::call('log', -1));
        self::assertInfinite(Harness::call('exp', 1000));
        self::assertSame(-\INF, Harness::call('log', 0));
        self::assertSame(-\INF, Harness::call('logb', 0));
        self::assertSame(\INF, Harness::call('logb', \INF));
        self::assertSame(\INF, Harness::call('significand', \INF));
    }

    #[DataProvider('binary')]
    public function testBinary(string $name, int|float $first, int|float $second, int|float $expected): void
    {
        self::assertEqualsWithDelta($expected, Harness::call($name, null, [$first, $second]), 1e-12, $name);
    }

    /**
     * @return iterable<string, array{string, int|float, int|float, int|float}>
     */
    public static function binary(): iterable
    {
        yield 'pow' => ['pow', 2, 10, 1024];
        yield 'pow root' => ['pow', 2, 0.5, 1.4142135623730951];
        yield 'pow zero exponent' => ['pow', 0, 0, 1];
        yield 'pow of one' => ['pow', 1, \INF, 1];
        yield 'pow zero base negative exponent' => ['pow', 0, -1, \INF];
        yield 'atan2' => ['atan2', 0, 1, 0];
        yield 'fmod' => ['fmod', 5.5, 2, 1.5];
        yield 'hypot' => ['hypot', 3, 4, 5];
        yield 'copysign' => ['copysign', 3, -1, -3];
        yield 'copysign negative zero' => ['copysign', 3, -0.0, -3];
        yield 'fdim' => ['fdim', 5, 3, 2];
        yield 'fdim clamps' => ['fdim', 3, 5, 0];
        yield 'fmax' => ['fmax', 1, 2, 2];
        yield 'fmax ignores nan' => ['fmax', \NAN, 2, 2];
        yield 'fmin' => ['fmin', 1, 2, 1];
        yield 'fmin ignores nan' => ['fmin', 1, \NAN, 1];
        yield 'drem' => ['drem', 10, 3, 1];
        yield 'drem rounds up' => ['drem', 11, 3, -1];
        yield 'drem tie to even down' => ['drem', 5, 2, 1];
        yield 'drem tie to even up' => ['drem', 7, 2, -1];
        yield 'remainder' => ['remainder', 11, 3, -1];
        yield 'ldexp' => ['ldexp', 1, 3, 8];
        yield 'scalb' => ['scalb', 3, 2, 12];
        yield 'scalbln' => ['scalbln', 3, 2, 12];
        yield 'ldexp huge exponent' => ['ldexp', 1, 5000, \INF];
        yield 'ldexp tiny exponent' => ['ldexp', 1, -5000, 0];
        yield 'nextafter' => ['nextafter', 1, 2, 1.0000000000000002];
        yield 'nextafter down' => ['nextafter', 1, 0, 0.9999999999999999];
        yield 'nextafter from zero' => ['nextafter', 0, 1, 4.9406564584124654E-324];
        yield 'nextafter same' => ['nextafter', 1, 1, 1];
        yield 'nexttoward' => ['nexttoward', 1, 2, 1.0000000000000002];
    }

    public function testRemainderByZeroIsNan(): void
    {
        self::assertNan(Harness::call('drem', null, [1, 0]));
        self::assertNan(Harness::call('drem', null, [\INF, 1]));
        self::assertSame(3, Harness::call('drem', null, [3, \INF]));
    }

    public function testFma(): void
    {
        self::assertSame(7, Harness::call('fma', null, [2, 3, 1]));
    }

    public function testFrexp(): void
    {
        self::assertSame([0.5, 4], Harness::call('frexp', 8));
        self::assertSame([-0.75, 2], Harness::call('frexp', -3));
        self::assertSame([0, 0], Harness::call('frexp', 0));
        self::assertSame([0.5, -1073], Harness::call('frexp', 5.0e-324));
    }

    public function testFrexpOfInfinityAndNan(): void
    {
        $infinite = Harness::call('frexp', \INF);
        self::assertIsArray($infinite);
        self::assertSame(\INF, $infinite[0]);
        $nan = Harness::call('frexp', \NAN);
        self::assertIsArray($nan);
        self::assertNan($nan[0]);
    }

    public function testModf(): void
    {
        self::assertSame([0.5, 3], Harness::call('modf', 3.5));
        self::assertSame([-0.5, -3], Harness::call('modf', -3.5));
        self::assertSame([0, 4], Harness::call('modf', 4));
        $infinite = Harness::call('modf', \INF);
        self::assertIsArray($infinite);
        self::assertSame(0, $infinite[0]);
        self::assertSame(\INF, $infinite[1]);
    }

    #[DataProvider('special')]
    public function testSpecialFunctions(string $name, int|float $input, float $expected): void
    {
        $tolerance = str_starts_with($name, 'y') ? 1e-7 : 1e-10;
        $result    = Harness::call($name, $input);
        self::assertIsNumeric($result);
        self::assertEqualsWithDelta($expected, (float)$result, max($tolerance, abs($expected) * 1e-10), $name);
    }

    /**
     * @return iterable<string, array{string, int|float, float}>
     */
    public static function special(): iterable
    {
        yield 'lgamma 5' => ['lgamma', 5, 3.1780538303479458];
        yield 'lgamma half' => ['lgamma', 0.5, 0.5723649429247001];
        yield 'lgamma negative' => ['lgamma', -0.5, 1.2655121234846454];
        yield 'gamma is lgamma' => ['gamma', 5, 3.1780538303479458];
        yield 'tgamma 5' => ['tgamma', 5, 24.0];
        yield 'tgamma half' => ['tgamma', 0.5, 1.7724538509055159];
        yield 'tgamma negative half' => ['tgamma', -0.5, -3.544907701811032];
        yield 'tgamma 1' => ['tgamma', 1, 1.0];
        yield 'erf small' => ['erf', 0.5, 0.5204998778130465];
        yield 'erf one' => ['erf', 1, 0.8427007929497149];
        yield 'erf large' => ['erf', 3, 0.9999779095030014];
        yield 'erf negative' => ['erf', -1, -0.8427007929497149];
        yield 'erf saturates' => ['erf', 7, 1.0];
        yield 'erfc one' => ['erfc', 1, 0.15729920705028513];
        yield 'erfc three' => ['erfc', 3, 2.209049699858544e-05];
        yield 'erfc ten' => ['erfc', 10, 2.088487583762545e-45];
        yield 'j0' => ['j0', 1, 0.7651976865579666];
        yield 'j0 large' => ['j0', 10, -0.2459357644513483];
        yield 'j1' => ['j1', 1, 0.4400505857449335];
        yield 'y0' => ['y0', 1, 0.08825696421567697];
        yield 'y0 large' => ['y0', 10, 0.05567116728359939];
        yield 'y1' => ['y1', 1, -0.7812128213002887];
        yield 'y1 large' => ['y1', 10, 0.24901542420695388];
        yield 'j0 asymptotic' => ['j0', 30, -0.0863679835810402];
        yield 'j0 asymptotic negative' => ['j0', -30, -0.0863679835810402];
        yield 'j1 asymptotic' => ['j1', 30, -0.11875106261662292];
        yield 'j1 asymptotic negative' => ['j1', -30, 0.11875106261662292];
        yield 'y0 asymptotic' => ['y0', 30, -0.11729573168666403];
        yield 'y1 asymptotic' => ['y1', 30, 0.08442557066174719];
    }

    public function testGammaEdges(): void
    {
        self::assertSame(\INF, Harness::call('lgamma', 0));
        self::assertSame(\INF, Harness::call('lgamma', -2));
        self::assertNan(Harness::call('tgamma', -2));
        self::assertSame(\INF, Harness::call('tgamma', 200));
        self::assertSame(\INF, Harness::call('tgamma', 0));
        self::assertSame(-\INF, Harness::call('y0', 0));
        self::assertNan(Harness::call('y0', -1));
        self::assertNan(Harness::call('y1', -1));
    }

    public function testLgammaR(): void
    {
        $positive = Harness::call('lgamma_r', 5);
        self::assertIsArray($positive);
        self::assertEqualsWithDelta(3.1780538303479458, $positive[0], 1e-12);
        self::assertSame(1, $positive[1]);
        $negative = Harness::call('lgamma_r', -0.5);
        self::assertIsArray($negative);
        self::assertSame(-1, $negative[1]);
    }

    public function testBesselOfHigherOrder(): void
    {
        self::assertEqualsWithDelta(0.1149034849319005, Harness::call('jn', null, [2, 1]), 1e-12);
        self::assertEqualsWithDelta(-1.6506826068162546, Harness::call('yn', null, [2, 1]), 1e-6);
        self::assertEqualsWithDelta(0.1149034849319005, Harness::call('jn', null, [-2, 1]), 1e-12);
    }

    public function testFrexpHelpersAreExposed(): void
    {
        self::assertSame([0.5, 1], MathFunctions::frexp(1.0));
        self::assertSame(8.0, MathFunctions::scalb(1.0, 3.0));
    }
}
