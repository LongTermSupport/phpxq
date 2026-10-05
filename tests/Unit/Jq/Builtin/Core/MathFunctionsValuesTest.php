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
 * A case is `[input, expected, tolerance]` for a builtin taking its input from `.`, or `[[arguments], expected,
 * tolerance]` for one taking arguments. The tolerance is relative (scaled by max(1, |expected|)); 0 means the
 * result must be identical, signed zero included.
 *
 * @internal
 */
final class MathFunctionsValuesTest extends TestCase
{
    private const float TIGHT = 1e-12;

    private const float RESIDUAL = 1e-11;

    private const float LOOSE = 1e-9;

    private const float EXACT = 0.0;

    /**
     * @param list<int|float>|int|float $arguments
     */
    #[DataProvider('values')]
    public function testValues(string $name, array|int|float $arguments, int|float $expected, float $tolerance): void
    {
        $result = \is_array($arguments) ? self::call($name, null, ...$arguments) : self::call($name, $arguments);

        if (\is_float($expected) && is_nan($expected)) {
            self::assertNan($result, $name);

            return;
        }

        if (0.0 === $tolerance) {
            self::assertSame($expected, $result, $name);
            if (0.0 === $expected) {
                self::assertSame(fdiv(1.0, $expected), fdiv(1.0, self::numberOf($result)), $name . ' sign of zero');
            }

            return;
        }

        self::assertEqualsWithDelta($expected, self::numberOf($result), $tolerance * max(1.0, abs($expected)), $name);
    }

    /**
     * @return iterable<string, array{string, list<int|float>|int|float, int|float, float}>
     */
    public static function values(): iterable
    {
        $table = [
            'acos'        => [[0.5, 1.0471975511965979]],
            'asin'        => [[0.5, 0.5235987755982989]],
            'atan'        => [[0.5, 0.4636476090008061]],
            'acosh'       => [[2, 1.3169578969248166]],
            'asinh'       => [[0.5, 0.48121182505960347]],
            'atanh'       => [[0.5, 0.5493061443340548]],
            'cosh'        => [[0.5, 1.1276259652063807]],
            'sinh'        => [[0.5, 0.5210953054937474]],
            'tan'         => [[0.5, 0.5463024898437905]],
            'tanh'        => [[0.5, 0.46211715726000974]],
            'cos'         => [[0.5, 0.8775825618903728]],
            'sin'         => [[0.5, 0.479425538604203]],
            'exp'         => [[0.5, 1.6487212707001282]],
            'expm1'       => [[0.5, 0.6487212707001282]],
            'log1p'       => [[0.5, 0.4054651081081644]],
            'log10'       => [[0.5, -0.3010299956639812]],
            'log'         => [[0.5, -0.6931471805599453]],
            'exp10'       => [[0.5, 3.1622776601683795]],
            'pow10'       => [[0.5, 3.1622776601683795]],
            'exp2'        => [[0.5, 1.4142135623730951]],
            'sqrt'        => [[0.5, 0.7071067811865476]],
            'gamma'       => [[0.5, 0.5723649429247001]],
            'log2'        => [
                [3, 1.584962500721156],
                [8, 3.0],
                [0.25, -2.0],
                [1, 0.0],
                [1024, 10, self::EXACT],
                [0.0009765625, -10, self::EXACT],
                [2.0 ** -1066, -1066, self::EXACT],
                [2.0 ** -1023, -1023, self::EXACT],
            ],
            'lgamma'      => [
                [0.5, 0.5723649429247001],
                [-0.5, 1.2655121234846454],
                [-2.5, -0.05624371649767407],
                [0.3, 1.0957979948180756],
                [-1.7, 0.9218446876946375],
                [5, 3.1780538303479458],
                [-3, \INF, self::EXACT],
                [0, \INF, self::EXACT],
                [\INF, \INF, self::EXACT],
                [\NAN, \NAN],
            ],
            'tgamma'      => [
                [0.5, 1.772453850905516],
                [-0.5, -3.5449077018110318],
                [-1.5, 2.363271801207355],
                [4.5, 11.63172839656745],
                [171, 7.257415615307999e306],
                [-2.3, -1.447107394255918],
                [-1.7, 2.513923519065202],
                [-0.3, -4.326851108825192],
                [1, 1, self::EXACT],
                [2, 1, self::EXACT],
                [3, 2, self::EXACT],
                [5, 24, self::EXACT],
                [6, 120, self::EXACT],
                [171, 7.257415615307994e306, self::EXACT],
                [172, \INF, self::EXACT],
                [0, \INF, self::EXACT],
                [-0.0, -\INF, self::EXACT],
                [\INF, \INF, self::EXACT],
                [-2, \NAN],
                [-1, \NAN],
                [-\INF, \NAN],
                [\NAN, \NAN],
            ],
            'erf'         => [
                [0.5, 0.5204998778130465],
                [-0.5, -0.5204998778130465],
                [3, 0.9999779095030014],
                [-3, -0.9999779095030014],
                [2.4999, 0.9995928300996666],
                [2.6, 0.9997639655834707],
                [-7, -1.0],
                [7, 1.0],
                [6, 1, self::EXACT],
                [-6, -1, self::EXACT],
                [0, 0, self::EXACT],
                [\NAN, \NAN],
            ],
            'erfc'        => [
                [0.5, 0.4795001221869535],
                [1, 0.15729920705028513],
                [-1, 1.842700792949715],
                [-3, 1.9999779095030015],
                [2.4, 0.0006885138966450789],
                [2.5, 0.0004069520174449589],
                [3, 2.2090496998585438e-5],
                [10, 2.088487583762545e-45],
                [26, 5.663192408856143e-296],
                [27, 5.23705e-319, self::EXACT],
                [28, 0, self::EXACT],
                [30, 0, self::EXACT],
                [2.5, 0.00040695201744495903, self::EXACT],
                [\NAN, \NAN],
            ],
            'j0'          => [
                [0.5, 0.9384698072408129],
                [1, 0.7651976865579666],
                [-1, 0.7651976865579666],
                [24, -0.05623027416685926],
                [25, 0.09626678327595814],
                [30, -0.08636798358104021],
                [-30, -0.08636798358104021],
                [100, 0.01998585030422312],
                [25, 0.09626678327595813, self::EXACT],
                [\INF, 0, self::EXACT],
                [-\INF, 0, self::EXACT],
                [\NAN, \NAN],
            ],
            'j1'          => [
                [0.5, 0.2422684576748739],
                [1, 0.4400505857449335],
                [-1, -0.4400505857449335],
                [24.5, -0.1589784118193281],
                [25, -0.1253502495802899],
                [30, -0.11875106261662292],
                [-30, 0.11875106261662292],
                [50, -0.09751182812517516],
                [25, -0.1253502495802899, self::EXACT],
                [\INF, 0, self::EXACT],
                [\NAN, \NAN],
            ],
            'y0'          => [
                [0.5, -0.44451873368207534],
                [1, 0.08825697139770806],
                [5, -0.30851762016522144],
                [7.9, 0.2065209247930682],
                [8, 0.22352148924968093],
                [8.5, 0.27020510526502023],
                [10, 0.05567116743095123],
                [20, 0.06264059669728692],
                [24.9, -0.13649918402968875],
                [25, -0.12724943226800614],
                [30, -0.11729573168666402],
                [100, -0.07724431336508317],
                [0, -\INF, self::EXACT],
                [\INF, 0, self::EXACT],
                [-1, \NAN],
                [\NAN, \NAN],
            ],
            'y1'          => [
                [0.5, -1.471472391865222],
                [1, -0.7812128209531197],
                [5, 0.1478631408862232],
                [7.9, -0.18172108255924457],
                [8, -0.1580604618351462],
                [8.5, -0.026168679623921918],
                [10, 0.24901542405799407],
                [20, -0.16551161434644293],
                [24.9, -0.08600255744898946],
                [25, -0.09882996478323743],
                [30, 0.08442557066174722],
                [100, -0.020372312002759792],
                [0, -\INF, self::EXACT],
                [\INF, 0, self::EXACT],
                [-1, \NAN],
                [\NAN, \NAN],
            ],
            'cbrt'        => [
                [0.5, 0.7937005259840998],
                [-1000, -10.0],
                [1000, 10.0],
                [27, 3.0],
                [-27, -3.0],
                [2, 1.2599210498948732, self::EXACT],
                [3, 1.4422495703074083, self::EXACT],
                [5, 1.7099759466766968, self::EXACT],
                [7, 1.9129311827723892, self::EXACT],
                [10, 2.154434690031884, self::EXACT],
                [100, 4.641588833612778, self::EXACT],
                [1.0e10, 2154.4346900318837, self::EXACT],
                [0.001, 0.1, self::EXACT],
                [-3, -1.4422495703074083, self::EXACT],
                [-5, -1.7099759466766968, self::EXACT],
                [-7, -1.9129311827723892, self::EXACT],
                [123456, 49.79327984674048, self::EXACT],
                [-1.0e300, -1.0e100, self::EXACT],
                [\INF, \INF, self::EXACT],
                [0, 0, self::EXACT],
                [\NAN, \NAN],
                ...self::perfectCubes(),
            ],
            'ceil'        => [[1.2, 2.0], [-1.2, -1.0], [3, 3, self::EXACT]],
            'floor'       => [[1.7, 1.0], [-1.2, -2.0], [3.0, 3, self::EXACT]],
            'round'       => [[2.4, 2.0], [2.6, 3.0], [-2.6, -3.0], [2.5, 3.0]],
            'rint'        => [[2.5, 2.0], [3.5, 4.0], [-2.5, -2.0]],
            'nearbyint'   => [[2.6, 3.0]],
            'trunc'       => [[2.6, 2.0], [-2.6, -2.0], [0.75, 0, self::EXACT], [-0.25, -0.0, self::EXACT]],
            'fabs'        => [[-2.5, 2.5], [-\INF, \INF, self::EXACT]],
            'significand' => [
                [10, 1.25],
                [-10, -1.25],
                [0, 0, self::EXACT],
                [1, 1, self::EXACT],
                [\NAN, \NAN],
            ],
            'logb'        => [
                [10, 3.0],
                [0.1, -4.0],
                [0, -\INF, self::EXACT],
                [\INF, \INF, self::EXACT],
                [\NAN, \NAN],
            ],
            'atan2'       => [[[1, 1], 0.7853981633974483], [[1, 2], 0.4636476090008061]],
            'drem'        => [
                [[5, 3], -1.0],
                [[7, 3], 1.0],
                [[5, 2], 1.0],
                [[3, 2], -1.0],
                [[-5, 3], 1.0],
                [[5, -3], -1.0],
                [[1, \INF], 1.0],
                [[9, 2], 1, self::EXACT],
                [[7, 2], -1, self::EXACT],
                [[-7, 2], 1, self::EXACT],
                [[1, \NAN], \NAN],
                [[\NAN, 1], \NAN],
                [[\INF, 1], \NAN],
                [[1, 0], \NAN],
            ],
            'remainder'   => [[[5, 3], -1.0]],
            'fdim'        => [
                [[5, 3], 2.0],
                [[3, 5], 0.0],
                [[3, 3], 0, self::EXACT],
                [[\NAN, 1], \NAN],
                [[1, \NAN], \NAN],
            ],
            'fmax'        => [[[3, 2], 3.0], [[2, 3], 3.0], [[\NAN, 1], 1.0], [[1, \NAN], 1.0]],
            'fmin'        => [[[3, 2], 2.0], [[2, 3], 2.0], [[\NAN, 1], 1.0], [[1, \NAN], 1.0]],
            'fmod'        => [[[5.5, 2], 1.5]],
            'hypot'       => [[[3, 4], 5.0]],
            'copysign'    => [
                [[3, -1], -3.0],
                [[-3, 1], 3.0],
                [[3, 0], 3, self::EXACT],
                [[3, -0.0], -3, self::EXACT],
                [[3, -0.5], -3.0],
                [[3, 0.5], 3.0],
                [[0, -1], -0.0, self::EXACT],
                [[0, 1], 0, self::EXACT],
            ],
            'pow'         => [
                [[2, 3], 8.0],
                [[2, 0], 1.0],
                [[5, 0], 1.0],
                [[1, \NAN], 1.0],
                [[0, 2], 0.0],
                [[1.5, 2], 2.25],
                [[0, 0], 1.0],
                [[2, -1], 0.5, self::EXACT],
                [[2, -2], 0.25, self::EXACT],
                [[0, -1], \INF, self::EXACT],
                [[0, -3], \INF, self::EXACT],
                [[0, -1.5], \INF, self::EXACT],
                [[0, -\INF], \INF, self::EXACT],
                [[-0.0, -3], -\INF, self::EXACT],
                [[-0.0, -1], -\INF, self::EXACT],
                [[-0.0, -2], \INF, self::EXACT],
                [[-0.0, -1.5], \INF, self::EXACT],
            ],
            'nextafter'   => [
                [[1, 2], 1.0000000000000002, self::EXACT],
                [[1, 0], 0.9999999999999999, self::EXACT],
                [[-1, -2], -1.0000000000000002, self::EXACT],
                [[-1, 0], -0.9999999999999999, self::EXACT],
                [[0, 1], 5.0e-324, self::EXACT],
                [[0, -1], -5.0e-324, self::EXACT],
                [[1.5, 1.5], 1.5, self::EXACT],
                [[2, 1], 1.9999999999999998, self::EXACT],
                [[-2, -1], -1.9999999999999998, self::EXACT],
                [[\NAN, 1], \NAN],
                [[1, \NAN], \NAN],
            ],
            'nexttoward'  => [[[1, 2], 1.0000000000000002, self::EXACT]],
            'scalb'       => [
                [[3, 4], 48.0],
                [[1, -2], 0.25],
                [[5.0e-324, 1100], 67108864.0],
                [[1.0e300, -1100], 7.362151829022863e-32],
                [[0, 5], 0.0],
                [[1, 4001], \INF, self::EXACT],
                [[-1, 4001], -\INF, self::EXACT],
                [[1, -4001], 0, self::EXACT],
                [[\INF, 5], \INF, self::EXACT],
                [[1, \NAN], \NAN],
                [[0, \NAN], \NAN],
                [[\INF, \NAN], \NAN],
            ],
            'scalbln'     => [[[3, 4], 48.0]],
            'ldexp'       => [
                [[3, 4], 48.0],
                [[3, -2], 0.75],
                [[5.0e-324, 1100], 67108864.0],
                [[1.0e300, -1100], 7.362151829022863e-32],
            ],
            'fma'         => [[[2, 3, 4], 10, self::EXACT], [[2, 3, -4], 2, self::EXACT]],
            'jn'          => [
                [[2, 1], 0.11490348493190049, self::RESIDUAL],
                [[-2, 1], 0.11490348493190049, self::RESIDUAL],
                [[1, 1], 0.4400505857449335, self::RESIDUAL],
                [[-1, 1], -0.4400505857449335, self::RESIDUAL],
                [[-3, 1], -0.019563353982668407, self::RESIDUAL],
                [[0, 1], 0.7651976865579666, self::RESIDUAL],
                [[3, 30], 0.12921122875972496, self::RESIDUAL],
                [[-3, 30], -0.12921122875972496, self::RESIDUAL],
                [[3, -30], -0.12921122875972496, self::RESIDUAL],
                [[-3, -30], 0.12921122875972496, self::RESIDUAL],
                [[2, -30], 0.07845124607326535, self::RESIDUAL],
                [[2, 30], 0.07845124607326535, self::RESIDUAL],
                [[10, 30], -0.12987689399858876, self::RESIDUAL],
                [[5, 40], 0.12257346597711778, self::RESIDUAL],
                [[10, 5], 0.0014678026473104741, self::RESIDUAL],
                [[20, 10], 1.1513369247813403e-5, self::RESIDUAL],
                [[3, 25], 0.10834308106150889, self::RESIDUAL],
                [[1, 24.9], -0.13485569953140886, self::RESIDUAL],
                [[15, 26], 0.1702191868752263, self::RESIDUAL],
                [[-15, 26], -0.1702191868752263, self::RESIDUAL],
                [[20, 30], 0.004831019993404073, self::RESIDUAL],
                [[12, 26], -0.16109040864391322, self::RESIDUAL],
                [[40, 60], -0.07764619740471508, self::RESIDUAL],
                [[8, 25], 0.15300616665739894, self::RESIDUAL],
                [[7, -26], 0.1362100784917583, self::RESIDUAL],
            ],
            'yn'          => [
                [[0, 1], 0.08825697139770806, self::LOOSE],
                [[1, 1], -0.7812128209531197, self::LOOSE],
                [[2, 1], -1.6506826133039476, self::LOOSE],
                [[2, 2], -0.6174080972254276, self::LOOSE],
                [[3, 1], -5.821517632262671, self::LOOSE],
                [[3, 2], -1.1277837627412945, self::LOOSE],
                [[-1, 1], 0.7812128209531197, self::LOOSE],
                [[-2, 1], -1.6506826133039476, self::LOOSE],
                [[-3, 2], 1.1277837627412945, self::LOOSE],
                [[0, 30], -0.11729573168666402, self::LOOSE],
                [[2, 30], 0.12292410306411383, self::LOOSE],
                [[5, 12], -0.22981794649630774, self::LOOSE],
            ],
        ];

        foreach ($table as $name => $cases) {
            foreach ($cases as $case) {
                $tolerance = $case[2] ?? self::TIGHT;
                $label     = $name . ' ' . var_export($case[0], true) . ' => ' . var_export($case[1], true) . ' (' . var_export($tolerance, true) . ')';

                yield $label => [$name, $case[0], $case[1], $tolerance];
            }
        }
    }

    /**
     * @param list<mixed> $expected
     */
    #[DataProvider('pairs')]
    public function testPairs(string $name, int|float $input, array $expected): void
    {
        $result = self::call($name, $input);
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertEqualsWithDelta($expected[0], self::numberOf($result[0]), self::TIGHT, $name);
        self::assertSame($expected[1], $result[1], $name);
    }

    /**
     * @return iterable<string, array{string, int|float, array{int|float, int|float}}>
     */
    public static function pairs(): iterable
    {
        $table = [
            'frexp'    => [
                [8, [0.5, 4]],
                [0.1, [0.8, -3]],
                [-8, [-0.5, 4]],
                [0, [0, 0]],
                [5.0e-324, [0.5, -1073]],
                [1.0e-310, [0.5752618031559393, -1029]],
                [2.2250738585072014e-308, [0.5, -1021]],
                [\INF, [\INF, 0]],
            ],
            'modf'     => [
                [3.5, [0.5, 3]],
                [-3.5, [-0.5, -3]],
                [0.25, [0.25, 0]],
                [\INF, [0, \INF]],
                [-\INF, [0, -\INF]],
            ],
            'lgamma_r' => [
                [-2.5, [-0.05624371649767407, -1]],
                [-1.5, [0.8600470153764809, 1]],
                [2.5, [0.2846828704729192, 1]],
                [-0.5, [1.2655121234846454, -1]],
                [-3.5, [-1.309006684993042, 1]],
                [-2.3, [0.36956666345500805, -1]],
                [-1.7, [0.9218446876946375, 1]],
                [0, [\INF, 1]],
                [-4, [\INF, 1]],
            ],
        ];

        foreach ($table as $name => $cases) {
            foreach ($cases as [$input, $expected]) {
                yield $name . ' ' . var_export($input, true) => [$name, $input, $expected];
            }
        }
    }

    public function testModfKeepsTheSignOfTheFractionOfInfinity(): void
    {
        $negative = self::call('modf', -\INF);
        self::assertIsArray($negative);
        self::assertSame(-\INF, fdiv(1.0, self::numberOf($negative[0])));

        $positive = self::call('modf', \INF);
        self::assertIsArray($positive);
        self::assertSame(\INF, fdiv(1.0, self::numberOf($positive[0])));
    }

    public function testFrexpAndScalbArePublicHelpers(): void
    {
        self::assertSame([0.5, -1073], MathFunctions::frexp(5.0e-324));
        self::assertSame([0.75, -1021], MathFunctions::frexp(2.2250738585072014e-308 * 1.5));
        self::assertSame(67108864.0, MathFunctions::scalb(5.0e-324, 1100.0));
        self::assertSame(7.362151829022863e-32, MathFunctions::scalb(1.0e300, -1100.0));
        self::assertSame(2.0, MathFunctions::scalb(1.0, 1.9));
    }

    /**
     * @return list<array{int, int, float}>
     */
    private static function perfectCubes(): array
    {
        $cubes = [];
        for ($root = 2; $root <= 40; ++$root) {
            $cubes[] = [$root ** 3, $root, self::EXACT];
            $cubes[] = [-($root ** 3), -$root, self::EXACT];
        }

        return $cubes;
    }

    private static function numberOf(mixed $value): float
    {
        self::assertIsNumeric($value);

        return (float)$value;
    }

    private static function call(string $name, int|float|null $input, int|float ...$args): mixed
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
