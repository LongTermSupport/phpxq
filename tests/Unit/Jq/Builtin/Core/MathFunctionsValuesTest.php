<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use InvalidArgumentException;
use LTS\PhpXq\Jq\Builtin\Core\MathFunctions;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\FakeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Values of the libm builtins, each case registering the builtins into a fresh registry so the registration
 * table itself is exercised. Expected values are the ones libm (jq 1.6) returns; the Y Bessel functions use
 * Numerical Recipes rational approximations, so their expectations are those approximations' own outputs.
 *
 * @internal
 */
final class MathFunctionsValuesTest extends TestCase
{
    /**
     * @param list<int|float> $args
     */
    #[DataProvider('approximate')]
    public function testApproximateValues(string $name, int|float|null $input, array $args, float $expected, float $delta): void
    {
        $result = self::call($name, $input, $args);
        self::assertIsNumeric($result);
        self::assertEqualsWithDelta($expected, (float)$result, $delta, $name);
    }

    /**
     * @return iterable<string, array{string, int|float|null, list<int|float>, float, float}>
     */
    public static function approximate(): iterable
    {
        $unary = [
            ['acos', 0.5, 1.0471975511965979],
            ['asin', 0.5, 0.5235987755982989],
            ['atan', 0.5, 0.4636476090008061],
            ['acosh', 2, 1.3169578969248166],
            ['asinh', 0.5, 0.48121182505960347],
            ['atanh', 0.5, 0.5493061443340548],
            ['cosh', 0.5, 1.1276259652063807],
            ['sinh', 0.5, 0.5210953054937474],
            ['tan', 0.5, 0.5463024898437905],
            ['tanh', 0.5, 0.46211715726000974],
            ['cos', 0.5, 0.8775825618903728],
            ['sin', 0.5, 0.479425538604203],
            ['exp', 0.5, 1.6487212707001282],
            ['expm1', 0.5, 0.6487212707001282],
            ['log1p', 0.5, 0.4054651081081644],
            ['log10', 0.5, -0.3010299956639812],
            ['log', 0.5, -0.6931471805599453],
            ['exp10', 0.5, 3.1622776601683795],
            ['pow10', 0.5, 3.1622776601683795],
            ['exp2', 0.5, 1.4142135623730951],
            ['log2', 3, 1.584962500721156],
            ['sqrt', 0.5, 0.7071067811865476],
            ['gamma', 0.5, 0.5723649429247001],
            ['lgamma', 0.5, 0.5723649429247001],
            ['lgamma', -0.5, 1.2655121234846454],
            ['lgamma', -2.5, -0.05624371649767407],
            ['lgamma', 0.3, 1.0957979948180756],
            ['lgamma', -1.7, 0.9218446876946375],
            ['tgamma', -2.3, -1.447107394255918],
            ['tgamma', -1.7, 2.513923519065202],
            ['tgamma', -0.3, -4.326851108825192],
            ['lgamma', 5, 3.1780538303479458],
            ['tgamma', 0.5, 1.772453850905516],
            ['tgamma', -0.5, -3.5449077018110318],
            ['tgamma', -1.5, 2.363271801207355],
            ['tgamma', 4.5, 11.63172839656745],
            ['tgamma', 171, 7.257415615307999e306],
            ['erf', 0.5, 0.5204998778130465],
            ['erf', -0.5, -0.5204998778130465],
            ['erf', 3, 0.9999779095030014],
            ['erf', -3, -0.9999779095030014],
            ['erf', 2.4999, 0.9995928300996666],
            ['erf', 2.6, 0.9997639655834707],
            ['erf', -7, -1.0],
            ['erf', 7, 1.0],
            ['erfc', 0.5, 0.4795001221869535],
            ['erfc', 1, 0.15729920705028513],
            ['erfc', -1, 1.842700792949715],
            ['erfc', -3, 1.9999779095030015],
            ['erfc', 2.4, 0.0006885138966450789],
            ['erfc', 2.5, 0.0004069520174449589],
            ['erfc', 3, 2.2090496998585438e-5],
            ['erfc', 10, 2.088487583762545e-45],
            ['erfc', 26, 5.663192408856143e-296],
            ['j0', 0.5, 0.9384698072408129],
            ['j0', 1, 0.7651976865579666],
            ['j0', -1, 0.7651976865579666],
            ['j0', 24, -0.05623027416685926],
            ['j0', 25, 0.09626678327595814],
            ['j0', 30, -0.08636798358104021],
            ['j0', -30, -0.08636798358104021],
            ['j0', 100, 0.01998585030422312],
            ['j1', 0.5, 0.2422684576748739],
            ['j1', 1, 0.4400505857449335],
            ['j1', -1, -0.4400505857449335],
            ['j1', 24.5, -0.1589784118193281],
            ['j1', 25, -0.1253502495802899],
            ['j1', 30, -0.11875106261662292],
            ['j1', -30, 0.11875106261662292],
            ['j1', 50, -0.09751182812517516],
            ['cbrt', 0.5, 0.7937005259840998],
            ['cbrt', 2, 1.2599210498948734],
            ['cbrt', -2, -1.2599210498948734],
            ['cbrt', 1000, 10.0],
            ['cbrt', -1000, -10.0],
            ['cbrt', 27, 3.0],
            ['cbrt', -27, -3.0],
            ['cbrt', 10, 2.154434690031884],
            ['cbrt', -10, -2.154434690031884],
            ['ceil', 1.2, 2.0],
            ['ceil', -1.2, -1.0],
            ['floor', 1.7, 1.0],
            ['floor', -1.2, -2.0],
            ['round', 2.4, 2.0],
            ['round', 2.6, 3.0],
            ['round', -2.6, -3.0],
            ['round', 2.5, 3.0],
            ['rint', 2.5, 2.0],
            ['rint', 3.5, 4.0],
            ['rint', -2.5, -2.0],
            ['nearbyint', 2.6, 3.0],
            ['trunc', 2.6, 2.0],
            ['trunc', -2.6, -2.0],
            ['fabs', -2.5, 2.5],
            ['significand', 10, 1.25],
            ['significand', -10, -1.25],
            ['logb', 10, 3.0],
            ['logb', 0.1, -4.0],
            ['log2', 8, 3.0],
            ['log2', 0.25, -2.0],
            ['log2', 1, 0.0],
        ];
        foreach ($unary as [$name, $input, $expected]) {
            yield $name . ' of ' . $input => [$name, $input, [], (float)$expected, 1e-12 * max(1.0, abs((float)$expected))];
        }

        // The Y functions are only accurate to about 1e-8 below 25, so compare them to libm loosely and pin
        // the approximation itself tightly.
        $y = [
            ['y0', 0.5, -0.44451873368207534],
            ['y0', 1, 0.08825697139770806],
            ['y0', 5, -0.30851762016522144],
            ['y0', 7.9, 0.2065209247930682],
            ['y0', 8, 0.22352148924968093],
            ['y0', 8.5, 0.27020510526502023],
            ['y0', 10, 0.05567116743095123],
            ['y0', 20, 0.06264059669728692],
            ['y0', 24.9, -0.13649918402968875],
            ['y0', 25, -0.12724943226800614],
            ['y0', 30, -0.11729573168666402],
            ['y0', 100, -0.07724431336508317],
            ['y1', 0.5, -1.471472391865222],
            ['y1', 1, -0.7812128209531197],
            ['y1', 5, 0.1478631408862232],
            ['y1', 7.9, -0.18172108255924457],
            ['y1', 8, -0.1580604618351462],
            ['y1', 8.5, -0.026168679623921918],
            ['y1', 10, 0.24901542405799407],
            ['y1', 20, -0.16551161434644293],
            ['y1', 24.9, -0.08600255744898946],
            ['y1', 25, -0.09882996478323743],
            ['y1', 30, 0.08442557066174722],
            ['y1', 100, -0.020372312002759792],
        ];
        foreach ($y as [$name, $input, $expected]) {
            yield $name . ' of ' . $input => [$name, $input, [], $expected, 1e-12];
        }

        $binary = [
            ['atan2', 1, 1, 0.7853981633974483],
            ['atan2', 1, 2, 0.4636476090008061],
            ['drem', 5, 3, -1.0],
            ['drem', 7, 3, 1.0],
            ['drem', 5, 2, 1.0],
            ['drem', 3, 2, -1.0],
            ['drem', -5, 3, 1.0],
            ['drem', 5, -3, -1.0],
            ['drem', 1, \INF, 1.0],
            ['remainder', 5, 3, -1.0],
            ['fdim', 5, 3, 2.0],
            ['fdim', 3, 5, 0.0],
            ['fmax', 3, 2, 3.0],
            ['fmax', 2, 3, 3.0],
            ['fmax', \NAN, 1, 1.0],
            ['fmax', 1, \NAN, 1.0],
            ['fmin', 3, 2, 2.0],
            ['fmin', 2, 3, 2.0],
            ['fmin', \NAN, 1, 1.0],
            ['fmin', 1, \NAN, 1.0],
            ['fmod', 5.5, 2, 1.5],
            ['hypot', 3, 4, 5.0],
            ['copysign', 3, -1, -3.0],
            ['copysign', -3, 1, 3.0],
            ['copysign', 3, 0, 3.0],
            ['copysign', 3, -0.0, -3.0],
            ['copysign', 3, -0.5, -3.0],
            ['copysign', 3, 0.5, 3.0],
            ['pow', 2, 3, 8.0],
            ['pow', 2, 0, 1.0],
            ['pow', 5, 0, 1.0],
            ['pow', 1, \NAN, 1.0],
            ['pow', 0, 2, 0.0],
            ['pow', 1.5, 2, 2.25],
            ['pow', 0, 0, 1.0],
            ['scalb', 3, 4, 48.0],
            ['scalb', 1, -2, 0.25],
            ['scalb', 5.0e-324, 1100, 67108864.0],
            ['scalb', 1.0e300, -1100, 7.362151829022863e-32],
            ['scalb', 0, 5, 0.0],
            ['scalbln', 3, 4, 48.0],
            ['ldexp', 3, 4, 48.0],
            ['ldexp', 3, -2, 0.75],
            ['ldexp', 5.0e-324, 1100, 67108864.0],
            ['ldexp', 1.0e300, -1100, 7.362151829022863e-32],
        ];
        foreach ($binary as [$name, $first, $second, $expected]) {
            yield $name . '(' . var_export($first, true) . ';' . var_export($second, true) . ')' => [$name, null, [$first, $second], $expected, 1e-12 * max(1.0, abs($expected))];
        }

        yield 'fma' => ['fma', null, [2, 3, 4], 10.0, 0.0];
        yield 'fma negative addend' => ['fma', null, [2, 3, -4], 2.0, 0.0];

        $bessel = [
            [2, 1, 0.11490348493190049],
            [-2, 1, 0.11490348493190049],
            [1, 1, 0.4400505857449335],
            [-1, 1, -0.4400505857449335],
            [-3, 1, -0.019563353982668407],
            [0, 1, 0.7651976865579666],
            [3, 30, 0.12921122875972496],
            [-3, 30, -0.12921122875972496],
            [3, -30, -0.12921122875972496],
            [-3, -30, 0.12921122875972496],
            [2, -30, 0.07845124607326535],
            [2, 30, 0.07845124607326535],
            [10, 30, -0.12987689399858876],
            [5, 40, 0.12257346597711778],
            [10, 5, 0.0014678026473104741],
            [20, 10, 1.1513369247813403e-5],
            [3, 25, 0.10834308106150889],
            [1, 24.9, -0.13485569953140886],
            [15, 26, 0.1702191868752263],
            [-15, 26, -0.1702191868752263],
            [20, 30, 0.004831019993404073],
            [12, 26, -0.16109040864391322],
            [40, 60, -0.07764619740471508],
            [8, 25, 0.15300616665739894],
            [7, -26, 0.1362100784917583],
        ];
        foreach ($bessel as [$order, $x, $expected]) {
            yield 'jn(' . $order . ';' . $x . ')' => ['jn', null, [$order, $x], $expected, 1e-11];
        }

        $yn = [
            [0, 1, 0.08825697139770806],
            [1, 1, -0.7812128209531197],
            [2, 1, -1.6506826133039476],
            [2, 2, -0.6174080972254276],
            [3, 1, -5.821517632262671],
            [3, 2, -1.1277837627412945],
            [-1, 1, 0.7812128209531197],
            [-2, 1, -1.6506826133039476],
            [-3, 2, 1.1277837627412945],
            [0, 30, -0.11729573168666402],
            [2, 30, 0.12292410306411383],
            [5, 12, -0.22981794649630774],
        ];
        foreach ($yn as [$order, $x, $expected]) {
            yield 'yn(' . $order . ';' . $x . ')' => ['yn', null, [$order, $x], $expected, 1e-9 * max(1.0, abs($expected))];
        }
    }

    /**
     * @param list<int|float> $args
     */
    #[DataProvider('exact')]
    public function testExactValues(string $name, int|float|null $input, array $args, int|float $expected): void
    {
        self::assertSame($expected, self::call($name, $input, $args), $name);
    }

    /**
     * @return iterable<string, array{string, int|float|null, list<int|float>, int|float}>
     */
    public static function exact(): iterable
    {
        yield 'nextafter up' => ['nextafter', null, [1, 2], 1.0000000000000002];
        yield 'nextafter down' => ['nextafter', null, [1, 0], 0.9999999999999999];
        yield 'nextafter negative away' => ['nextafter', null, [-1, -2], -1.0000000000000002];
        yield 'nextafter negative toward' => ['nextafter', null, [-1, 0], -0.9999999999999999];
        yield 'nextafter from zero up' => ['nextafter', null, [0, 1], 5.0e-324];
        yield 'nextafter from zero down' => ['nextafter', null, [0, -1], -5.0e-324];
        yield 'nextafter equal' => ['nextafter', null, [1.5, 1.5], 1.5];
        yield 'nextafter two to one' => ['nextafter', null, [2, 1], 1.9999999999999998];
        yield 'nextafter minus two toward minus one' => ['nextafter', null, [-2, -1], -1.9999999999999998];
        yield 'nexttoward' => ['nexttoward', null, [1, 2], 1.0000000000000002];
        yield 'pow of zero to a negative power' => ['pow', null, [0, -1], \INF];
        yield 'pow of zero to an odd negative power' => ['pow', null, [0, -3], \INF];
        yield 'pow of zero to a fractional negative power' => ['pow', null, [0, -1.5], \INF];
        yield 'pow of zero to negative infinity' => ['pow', null, [0, -\INF], \INF];
        yield 'pow of negative zero to an odd negative power' => ['pow', null, [-0.0, -3], -\INF];
        yield 'pow of negative zero to minus one' => ['pow', null, [-0.0, -1], -\INF];
        yield 'pow of negative zero to an even negative power' => ['pow', null, [-0.0, -2], \INF];
        yield 'pow of negative zero to a fractional negative power' => ['pow', null, [-0.0, -1.5], \INF];
        yield 'scalb overflow' => ['scalb', null, [1, 4001], \INF];
        yield 'scalb negative overflow' => ['scalb', null, [-1, 4001], -\INF];
        yield 'scalb underflow' => ['scalb', null, [1, -4001], 0];
        yield 'scalb of infinity' => ['scalb', null, [\INF, 5], \INF];
        yield 'frexp exponent of one' => ['logb', 1, [], 0];
        yield 'lgamma at a pole' => ['lgamma', -3, [], \INF];
        yield 'lgamma at zero' => ['lgamma', 0, [], \INF];
        yield 'lgamma of infinity' => ['lgamma', \INF, [], \INF];
        yield 'tgamma 1' => ['tgamma', 1, [], 1];
        yield 'tgamma 2' => ['tgamma', 2, [], 1];
        yield 'tgamma 3' => ['tgamma', 3, [], 2];
        yield 'tgamma 5' => ['tgamma', 5, [], 24];
        yield 'tgamma 6' => ['tgamma', 6, [], 120];
        yield 'tgamma 171' => ['tgamma', 171, [], 7.257415615307994e306];
        yield 'tgamma 172' => ['tgamma', 172, [], \INF];
        yield 'tgamma zero' => ['tgamma', 0, [], \INF];
        yield 'tgamma negative zero' => ['tgamma', -0.0, [], -\INF];
        yield 'tgamma infinity' => ['tgamma', \INF, [], \INF];
        yield 'erf saturates positive' => ['erf', 6, [], 1];
        yield 'erf saturates negative' => ['erf', -6, [], -1];
        yield 'erf of zero' => ['erf', 0, [], 0];
        yield 'erfc of 27' => ['erfc', 27, [], 5.23705e-319];
        yield 'erfc of 28' => ['erfc', 28, [], 0];
        yield 'erfc of 30' => ['erfc', 30, [], 0];
        yield 'erfc of 2.5' => ['erfc', 2.5, [], 0.00040695201744495903];
        yield 'j0 of infinity' => ['j0', \INF, [], 0];
        yield 'j0 of negative infinity' => ['j0', -\INF, [], 0];
        yield 'j1 of infinity' => ['j1', \INF, [], 0];
        yield 'y0 of zero' => ['y0', 0, [], -\INF];
        yield 'y1 of zero' => ['y1', 0, [], -\INF];
        yield 'y0 of infinity' => ['y0', \INF, [], 0];
        yield 'y1 of infinity' => ['y1', \INF, [], 0];
        yield 'logb of zero' => ['logb', 0, [], -\INF];
        yield 'logb of infinity' => ['logb', \INF, [], \INF];
        yield 'log2 of a power of two' => ['log2', 1024, [], 10];
        yield 'log2 of a negative power of two' => ['log2', 0.0009765625, [], -10];
        yield 'significand of zero' => ['significand', 0, [], 0];
        yield 'significand of one' => ['significand', 1, [], 1];
        yield 'fdim equal' => ['fdim', null, [3, 3], 0];
        yield 'drem tie even' => ['drem', null, [9, 2], 1];
        yield 'drem tie odd quotient' => ['drem', null, [7, 2], -1];
        yield 'drem negative tie' => ['drem', null, [-7, 2], 1];
        yield 'cbrt of two' => ['cbrt', 2, [], 1.2599210498948732];
        yield 'cbrt of three' => ['cbrt', 3, [], 1.4422495703074083];
        yield 'cbrt of five' => ['cbrt', 5, [], 1.7099759466766968];
        yield 'cbrt of seven' => ['cbrt', 7, [], 1.9129311827723892];
        yield 'cbrt of ten' => ['cbrt', 10, [], 2.154434690031884];
        yield 'cbrt of a hundred' => ['cbrt', 100, [], 4.641588833612778];
        yield 'cbrt of ten billion' => ['cbrt', 1.0e10, [], 2154.4346900318837];
        yield 'cbrt of a thousandth' => ['cbrt', 0.001, [], 0.1];
        yield 'cbrt of minus three' => ['cbrt', -3, [], -1.4422495703074083];
        yield 'cbrt of minus five' => ['cbrt', -5, [], -1.7099759466766968];
        yield 'cbrt of minus seven' => ['cbrt', -7, [], -1.9129311827723892];
        yield 'cbrt of a large number' => ['cbrt', 123456, [], 49.79327984674048];
        yield 'cbrt of minus a huge number' => ['cbrt', -1.0e300, [], -1.0e100];
        yield 'pow with a negative exponent' => ['pow', null, [2, -1], 0.5];
        yield 'pow with a negative even exponent' => ['pow', null, [2, -2], 0.25];
        yield 'log2 of a tiny power of two' => ['log2', 2.0 ** -1066, [], -1066];
        yield 'log2 of a denormal power of two' => ['log2', 2.0 ** -1023, [], -1023];
        yield 'j0 at the switch to the expansion' => ['j0', 25, [], 0.09626678327595813];
        yield 'j1 at the switch to the expansion' => ['j1', 25, [], -0.1253502495802899];
        yield 'cbrt of infinity' => ['cbrt', \INF, [], \INF];
        yield 'cbrt of zero' => ['cbrt', 0, [], 0];
        yield 'fabs of infinity' => ['fabs', -\INF, [], \INF];
        yield 'trunc of fraction' => ['trunc', 0.75, [], 0];
        yield 'ceil of integral' => ['ceil', 3, [], 3];
        yield 'floor of exactly integral float' => ['floor', 3.0, [], 3];
    }

    /**
     * @param list<int|float> $args
     */
    #[DataProvider('notANumber')]
    public function testNanValues(string $name, int|float|null $input, array $args): void
    {
        self::assertNan(self::call($name, $input, $args), $name);
    }

    /**
     * @return iterable<string, array{string, int|float|null, list<int|float>}>
     */
    public static function notANumber(): iterable
    {
        yield 'drem by nan' => ['drem', null, [1, \NAN]];
        yield 'drem of nan' => ['drem', null, [\NAN, 1]];
        yield 'drem of infinity' => ['drem', null, [\INF, 1]];
        yield 'drem by zero' => ['drem', null, [1, 0]];
        yield 'fdim of nan' => ['fdim', null, [\NAN, 1]];
        yield 'fdim by nan' => ['fdim', null, [1, \NAN]];
        yield 'nextafter of nan' => ['nextafter', null, [\NAN, 1]];
        yield 'nextafter toward nan' => ['nextafter', null, [1, \NAN]];
        yield 'scalb by nan' => ['scalb', null, [1, \NAN]];
        yield 'scalb of zero by nan' => ['scalb', null, [0, \NAN]];
        yield 'scalb of infinity by nan' => ['scalb', null, [\INF, \NAN]];
        yield 'lgamma of nan' => ['lgamma', \NAN, []];
        yield 'tgamma of a negative integer' => ['tgamma', -2, []];
        yield 'tgamma of minus one' => ['tgamma', -1, []];
        yield 'tgamma of negative infinity' => ['tgamma', -\INF, []];
        yield 'tgamma of nan' => ['tgamma', \NAN, []];
        yield 'erf of nan' => ['erf', \NAN, []];
        yield 'erfc of nan' => ['erfc', \NAN, []];
        yield 'j0 of nan' => ['j0', \NAN, []];
        yield 'j1 of nan' => ['j1', \NAN, []];
        yield 'y0 of nan' => ['y0', \NAN, []];
        yield 'y1 of nan' => ['y1', \NAN, []];
        yield 'y0 of a negative' => ['y0', -1, []];
        yield 'y1 of a negative' => ['y1', -1, []];
        yield 'logb of nan' => ['logb', \NAN, []];
        yield 'cbrt of nan' => ['cbrt', \NAN, []];
        yield 'significand of nan' => ['significand', \NAN, []];
    }

    public function testCbrtOfPerfectCubesIsExact(): void
    {
        for ($root = 2; $root <= 40; ++$root) {
            self::assertSame($root, self::call('cbrt', $root ** 3, []), 'cbrt of ' . $root ** 3);
            self::assertSame(-$root, self::call('cbrt', -($root ** 3), []), 'cbrt of ' . -($root ** 3));
        }
    }

    public function testNegativeZeroSurvivesTheTruncatingFunctions(): void
    {
        self::assertSame(-\INF, fdiv(1.0, self::resultAsFloat('copysign', [0, -1])));
        self::assertSame(-\INF, fdiv(1.0, self::resultAsFloat('trunc', -0.25)));
        self::assertSame(\INF, fdiv(1.0, self::resultAsFloat('copysign', [0, 1])));
    }

    /**
     * @param list<mixed> $expected
     */
    #[DataProvider('pairs')]
    public function testPairs(string $name, int|float $input, array $expected): void
    {
        $result = self::call($name, $input, []);
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertEqualsWithDelta($expected[0], $result[0], 1e-12, $name);
        self::assertSame($expected[1], $result[1], $name);
    }

    /**
     * @return iterable<string, array{string, int|float, array{int|float, int|float}}>
     */
    public static function pairs(): iterable
    {
        yield 'frexp of eight' => ['frexp', 8, [0.5, 4]];
        yield 'frexp of a tenth' => ['frexp', 0.1, [0.8, -3]];
        yield 'frexp of negative eight' => ['frexp', -8, [-0.5, 4]];
        yield 'frexp of zero' => ['frexp', 0, [0, 0]];
        yield 'frexp of the smallest denormal' => ['frexp', 5.0e-324, [0.5, -1073]];
        yield 'frexp of a denormal' => ['frexp', 1.0e-310, [0.5752618031559393, -1029]];
        yield 'frexp of the smallest normal' => ['frexp', 2.2250738585072014e-308, [0.5, -1021]];
        yield 'frexp of infinity' => ['frexp', \INF, [\INF, 0]];
        yield 'modf of 3.5' => ['modf', 3.5, [0.5, 3]];
        yield 'modf of negative 3.5' => ['modf', -3.5, [-0.5, -3]];
        yield 'modf of a quarter' => ['modf', 0.25, [0.25, 0]];
        yield 'modf of infinity' => ['modf', \INF, [0, \INF]];
        yield 'modf of negative infinity' => ['modf', -\INF, [0, -\INF]];
        yield 'lgamma_r of a negative with odd floor' => ['lgamma_r', -2.5, [-0.05624371649767407, -1]];
        yield 'lgamma_r of a negative with even floor' => ['lgamma_r', -1.5, [0.8600470153764809, 1]];
        yield 'lgamma_r of a positive' => ['lgamma_r', 2.5, [0.2846828704729192, 1]];
        yield 'lgamma_r of minus half' => ['lgamma_r', -0.5, [1.2655121234846454, -1]];
        yield 'lgamma_r of minus three and a half' => ['lgamma_r', -3.5, [-1.309006684993042, 1]];
        yield 'lgamma_r of a negative whose floor is odd and rounds even' => ['lgamma_r', -2.3, [0.36956666345500805, -1]];
        yield 'lgamma_r of a negative whose floor is even' => ['lgamma_r', -1.7, [0.9218446876946375, 1]];
        yield 'lgamma_r of zero' => ['lgamma_r', 0, [\INF, 1]];
        yield 'lgamma_r of a negative integer' => ['lgamma_r', -4, [\INF, 1]];
    }

    public function testModfOfNegativeInfinityKeepsTheSignOfTheFraction(): void
    {
        $result = self::call('modf', -\INF, []);
        self::assertIsArray($result);
        self::assertSame(-\INF, fdiv(1.0, (float)$result[0]));
    }

    public function testModfOfPositiveInfinityHasAPositiveZeroFraction(): void
    {
        $result = self::call('modf', \INF, []);
        self::assertIsArray($result);
        self::assertSame(\INF, fdiv(1.0, (float)$result[0]));
    }

    public function testFrexpIsPublicAndNormalisesDenormals(): void
    {
        self::assertSame([0.5, -1073], MathFunctions::frexp(5.0e-324));
        self::assertSame([0.75, -1021], MathFunctions::frexp(2.2250738585072014e-308 * 1.5));
        self::assertSame(67108864.0, MathFunctions::scalb(5.0e-324, 1100.0));
        self::assertSame(7.362151829022863e-32, MathFunctions::scalb(1.0e300, -1100.0));
        self::assertSame(2.0, MathFunctions::scalb(1.0, 1.9));
    }

    /**
     * @param int|float|list<int|float> $argument
     */
    private static function resultAsFloat(string $name, int|float|array $argument): float
    {
        $result = \is_array($argument) ? self::call($name, null, $argument) : self::call($name, $argument, []);
        self::assertIsNumeric($result);

        return (float)$result;
    }

    /**
     * @param list<int|float> $args
     */
    private static function call(string $name, int|float|null $input, array $args): mixed
    {
        $registry = new DefaultBuiltinRegistry();
        MathFunctions::register($registry);
        $builtin = $registry->lookup($name, \count($args));
        if (!$builtin instanceof ValueBuiltinInterface) {
            throw new InvalidArgumentException($name . '/' . \count($args) . ' is not a value builtin');
        }

        return $builtin->call(new FakeContext(), $input, ...$args);
    }
}
