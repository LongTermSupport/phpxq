<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use RoundingMode;

/**
 * The libm builtins: unary and binary math functions over doubles, plus frexp, modf and lgamma_r, which
 * return pairs. Arguments must be numbers; results are normalised with {@see Num::of()}.
 *
 * @internal
 */
final readonly class MathFunctions
{
    private const float LN2 = 0.6931471805599453;

    private const float ASYMPTOTIC_FROM = 25.0;

    /**
     * The Hankel expansion needs the argument to dominate the squared order, so a large order keeps the
     * integral form (whose cost grows with the argument) up to this argument.
     */
    private const float INTEGRAL_UP_TO = 100000.0;

    private const array Y0_COSINE_TERMS = [1.0, -0.1098628627e-2, 0.2734510407e-4, -0.2073370639e-5, 0.2093887211e-6];

    private const array Y0_SINE_TERMS = [-0.1562499995e-1, 0.1430488765e-3, -0.6911147651e-5, 0.7621095161e-6, -0.934945152e-7];

    private const array Y1_COSINE_TERMS = [1.0, 0.183105e-2, -0.3516396496e-4, 0.2457520174e-5, -0.240337019e-6];

    private const array Y1_SINE_TERMS = [0.04687499995, -0.2002690873e-3, 0.8449199096e-5, -0.88228987e-6, 0.105787412e-6];

    private const array LANCZOS = [
        0.99999999999980993,
        676.5203681218851,
        -1259.1392167224028,
        771.32342877765313,
        -176.61502916214059,
        12.507343278686905,
        -0.13857109526572012,
        9.9843695780195716e-6,
        1.5056327351493116e-7,
    ];

    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
    {
        foreach (self::unaryTable() as $name => $function) {
            $registry->register(new ValueFunction($name, 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => Num::of($function(self::arg($v)))));
        }

        $binary = [
            'atan2'      => atan2(...),
            'copysign'   => self::copysign(...),
            'drem'       => self::drem(...),
            'fdim'       => self::fdim(...),
            'fmax'       => self::fmax(...),
            'fmin'       => self::fmin(...),
            'fmod'       => fmod(...),
            'hypot'      => hypot(...),
            'nextafter'  => self::nextafter(...),
            'nexttoward' => self::nextafter(...),
            'pow'        => self::pow(...),
            'remainder'  => self::drem(...),
            'scalb'      => self::scalb(...),
        ];
        foreach ($binary as $name => $function) {
            $registry->register(new ValueFunction($name, 2, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => Num::of($function(self::arg($a[0]), self::arg($a[1])))));
        }

        $registry->register(new ValueFunction('ldexp', 2, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => Num::of(self::scalb(self::arg($a[0]), (float)Num::toInt(self::arg($a[1]))))));
        $registry->register(new ValueFunction('scalbln', 2, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => Num::of(self::scalb(self::arg($a[0]), (float)Num::toInt(self::arg($a[1]))))));
        $registry->register(new ValueFunction('fma', 3, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => Num::of(self::arg($a[0]) * self::arg($a[1]) + self::arg($a[2]))));
        $registry->register(new ValueFunction('jn', 2, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => Num::of(self::jn(Num::toInt(self::arg($a[0])), self::arg($a[1])))));
        $registry->register(new ValueFunction('yn', 2, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => Num::of(self::yn(Num::toInt(self::arg($a[0])), self::arg($a[1])))));
        $registry->register(new ValueFunction('frexp', 0, static function (RuntimeContextInterface $c, mixed $v): mixed {
            [$mantissa, $exponent] = self::frexp(self::arg($v));

            return [Num::of($mantissa), $exponent];
        }));
        $registry->register(new ValueFunction('modf', 0, static function (RuntimeContextInterface $c, mixed $v): mixed {
            $x = self::arg($v);
            if (is_infinite($x)) {
                return [Num::of(self::copysign(0.0, $x)), Num::of($x)];
            }

            $whole = self::trunc($x);

            return [Num::of($x - $whole), Num::of($whole)];
        }));
        $registry->register(new ValueFunction('lgamma_r', 0, static function (RuntimeContextInterface $c, mixed $v): mixed {
            $x = self::arg($v);

            return [Num::of(self::lgamma($x)), self::gammaSign($x)];
        }));
    }

    /**
     * Split a double into mantissa in [0.5, 1) and a power of two (zero, infinities and NaN come back as is).
     *
     * @return array{float, int}
     */
    public static function frexp(float $x): array
    {
        if (0.0 === $x || !is_finite($x)) {
            return [$x, 0];
        }

        $adjust = 0;
        if (abs($x) < \PHP_FLOAT_MIN) {
            $x *= 18014398509481984.0;
            $adjust = -54;
        }

        $bits     = self::bits($x);
        $exponent = (($bits >> 52) & 0x7FF) - 1022;
        $mantissa = self::fromBits(($bits & ~(0x7FF << 52)) | (1022 << 52));

        return [$mantissa, $exponent + $adjust];
    }

    public static function scalb(float $x, float $exponent): float
    {
        if (0.0 === $x || !is_finite($x) || is_nan($exponent)) {
            return is_nan($exponent) ? \NAN : $x;
        }

        if ($exponent > 4000) {
            return $x * \INF;
        }

        if ($exponent < -4000) {
            return $x * 0.0;
        }

        $remaining = (int)$exponent;
        while ($remaining > 1000) {
            $x *= 2.0 ** 1000;
            $remaining -= 1000;
        }

        while ($remaining < -1000) {
            $x *= 2.0 ** -1000;
            $remaining += 1000;
        }

        return $x * 2.0 ** $remaining;
    }

    /**
     * @return array<string, Closure(float): (float|int)>
     */
    private static function unaryTable(): array
    {
        return [
            'acos'        => acos(...),
            'acosh'       => acosh(...),
            'asin'        => asin(...),
            'asinh'       => asinh(...),
            'atan'        => atan(...),
            'atanh'       => atanh(...),
            'cbrt'        => self::cbrt(...),
            'ceil'        => ceil(...),
            'cos'         => cos(...),
            'cosh'        => cosh(...),
            'erf'         => self::erf(...),
            'erfc'        => self::erfc(...),
            'exp'         => exp(...),
            'exp10'       => self::exp10(...),
            'exp2'        => self::exp2(...),
            'expm1'       => expm1(...),
            'fabs'        => abs(...),
            'floor'       => floor(...),
            'gamma'       => self::lgamma(...),
            'j0'          => self::j0(...),
            'j1'          => self::j1(...),
            'lgamma'      => self::lgamma(...),
            'log'         => log(...),
            'log10'       => log10(...),
            'log1p'       => log1p(...),
            'log2'        => self::log2(...),
            'logb'        => self::logb(...),
            'nearbyint'   => self::rint(...),
            'pow10'       => self::exp10(...),
            'rint'        => self::rint(...),
            'round'       => round(...),
            'significand' => self::significand(...),
            'sin'         => sin(...),
            'sinh'        => sinh(...),
            'sqrt'        => sqrt(...),
            'tan'         => tan(...),
            'tanh'        => tanh(...),
            'tgamma'      => self::tgamma(...),
            'trunc'       => self::trunc(...),
            'y0'          => self::y0(...),
            'y1'          => self::y1(...),
        ];
    }

    private static function arg(mixed $value): float
    {
        if (!Num::isNumber($value)) {
            throw Problems::type($value, 'number required');
        }

        return Num::toFloat($value);
    }

    private static function bits(float $x): int
    {
        $unpacked = unpack('J', pack('E', $x));
        if (\is_array($unpacked) && \is_int($unpacked[1])) {
            return $unpacked[1];
        }

        return 0;
    }

    private static function fromBits(int $bits): float
    {
        $unpacked = unpack('E', pack('J', $bits));
        if (\is_array($unpacked) && \is_float($unpacked[1])) {
            return $unpacked[1];
        }

        return 0.0;
    }

    private static function significand(float $x): float
    {
        if (0.0 === $x || !is_finite($x)) {
            return $x;
        }

        [$mantissa] = self::frexp($x);

        return $mantissa * 2.0;
    }

    private static function logb(float $x): float
    {
        if (0.0 === $x) {
            return -\INF;
        }

        if (is_nan($x)) {
            return $x;
        }

        if (is_infinite($x)) {
            return \INF;
        }

        [, $exponent] = self::frexp($x);

        return (float)($exponent - 1);
    }

    private static function trunc(float $x): float
    {
        return $x < 0 ? ceil($x) : floor($x);
    }

    private static function rint(float $x): float
    {
        return round($x, 0, RoundingMode::HalfEven);
    }

    private static function cbrt(float $x): float
    {
        if (0.0 === $x || !is_finite($x)) {
            return $x;
        }

        $root = $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
        $near = round($root);
        if ($near * $near * $near === $x) {
            return $near;
        }

        return $root - ($root * $root * $root - $x) / (3 * $root * $root);
    }

    private static function exp2(float $x): float
    {
        return self::pow(2.0, $x);
    }

    private static function exp10(float $x): float
    {
        return self::pow(10.0, $x);
    }

    private static function log2(float $x): float
    {
        if ($x > 0 && is_finite($x)) {
            [$mantissa, $exponent] = self::frexp($x);
            if (0.5 === $mantissa) {
                return (float)($exponent - 1);
            }
        }

        return log($x) / self::LN2;
    }

    /**
     * C's pow for the cases PHP leaves to deprecations or different conventions.
     */
    private static function pow(float $x, float $y): float
    {
        if (0.0 === $y) {
            return 1.0;
        }

        if (1.0 === $x) {
            return 1.0;
        }

        if (0.0 === $x && $y < 0) {
            $odd = is_finite($y) && floor($y) === $y && 1.0 === fmod(abs($y), 2.0);

            return $odd && fdiv(1.0, $x) < 0 ? -\INF : \INF;
        }

        return $x ** $y;
    }

    private static function copysign(float $x, float $y): float
    {
        $negative = $y < 0 || (0.0 === $y && fdiv(1.0, $y) < 0);

        return $negative ? -abs($x) : abs($x);
    }

    private static function fdim(float $x, float $y): float
    {
        if (is_nan($x) || is_nan($y)) {
            return \NAN;
        }

        return $x > $y ? $x - $y : 0.0;
    }

    private static function fmax(float $x, float $y): float
    {
        if (is_nan($x)) {
            return $y;
        }

        return is_nan($y) ? $x : max($x, $y);
    }

    private static function fmin(float $x, float $y): float
    {
        if (is_nan($x)) {
            return $y;
        }

        return is_nan($y) ? $x : min($x, $y);
    }

    /**
     * IEEE 754 remainder: x minus y times the integer nearest to x/y (ties to even).
     */
    private static function drem(float $x, float $y): float
    {
        if (is_nan($x) || is_nan($y) || is_infinite($x) || 0.0 === $y) {
            return \NAN;
        }

        if (is_infinite($y)) {
            return $x;
        }

        $remainder = fmod($x, $y);
        $size      = abs($y);
        $half      = $size / 2;
        if (abs($remainder) > $half) {
            return $remainder - self::copysign($size, $remainder);
        }

        if (abs($remainder) === $half) {
            $quotient = round(($x - $remainder) / $y);
            if (1.0 === fmod(abs($quotient), 2.0)) {
                return $remainder - self::copysign($size, $remainder);
            }
        }

        return $remainder;
    }

    private static function nextafter(float $x, float $y): float
    {
        if (is_nan($x) || is_nan($y)) {
            return \NAN;
        }

        if ($x === $y) {
            return $y;
        }

        if (0.0 === $x) {
            return $y > 0 ? 4.9406564584124654E-324 : -4.9406564584124654E-324;
        }

        $bits = self::bits($x);
        $up   = ($y > $x) === ($x > 0);

        return self::fromBits($up ? $bits + 1 : $bits - 1);
    }

    private static function lgamma(float $x): float
    {
        if (is_nan($x)) {
            return $x;
        }

        if (is_infinite($x)) {
            return \INF;
        }

        if ($x <= 0 && floor($x) === $x) {
            return \INF;
        }

        if ($x < 0.5) {
            return log(\M_PI / abs(sin(\M_PI * $x))) - self::lgamma(1.0 - $x);
        }

        $x -= 1.0;
        $sum = self::LANCZOS[0];
        $t   = $x + 7.5;
        for ($i = 1; $i < 9; ++$i) {
            $sum += self::LANCZOS[$i] / ($x + $i);
        }

        return 0.5 * log(2 * \M_PI) + ($x + 0.5) * log($t) - $t + log($sum);
    }

    private static function gammaSign(float $x): int
    {
        if ($x >= 0 || is_nan($x) || floor($x) === $x) {
            return 1;
        }

        return 0 === (int)floor($x) % 2 ? 1 : -1;
    }

    private static function tgamma(float $x): float
    {
        if (is_nan($x) || \INF === $x) {
            return $x;
        }

        if ($x === -\INF) {
            return \NAN;
        }

        if (0.0 === $x) {
            return fdiv(1.0, $x);
        }

        if (floor($x) === $x) {
            if ($x < 0) {
                return \NAN;
            }

            if ($x <= 171) {
                $product = 1.0;
                for ($i = 2; $i < (int)$x; ++$i) {
                    $product *= $i;
                }

                return $product;
            }

            return \INF;
        }

        return self::gammaSign($x) * exp(self::lgamma($x));
    }

    private static function erf(float $x): float
    {
        if (is_nan($x)) {
            return $x;
        }

        $a = abs($x);
        if ($a >= 6.0) {
            return $x < 0 ? -1.0 : 1.0;
        }

        if ($a < 2.5) {
            $term = $a;
            $sum  = $a;
            for ($n = 1; $n < 200; ++$n) {
                $term *= 2 * $a * $a / (2 * $n + 1);
                $sum += $term;
                if ($term < 1e-17 * $sum) {
                    break;
                }
            }

            $value = 2 / sqrt(\M_PI) * exp(-$a * $a) * $sum;

            return $x < 0 ? -$value : $value;
        }

        $value = 1.0 - self::erfcPositive($a);

        return $x < 0 ? -$value : $value;
    }

    private static function erfc(float $x): float
    {
        if (is_nan($x)) {
            return $x;
        }

        if ($x < 2.5) {
            return 1.0 - self::erf($x);
        }

        return self::erfcPositive($x);
    }

    /**
     * erfc for x >= 2.5 by the continued fraction (modified Lentz).
     */
    private static function erfcPositive(float $x): float
    {
        if ($x > 27) {
            return 0.0;
        }

        $tail = $x;
        for ($k = 120; $k >= 1; --$k) {
            $tail = $x + $k / 2 / $tail;
        }

        return exp(-$x * $x) / sqrt(\M_PI) / $tail;
    }

    private static function besselIntegral(int $order, float $x): float
    {
        $steps = 64 + (int)(2 * abs($x)) + 4 * abs($order);
        $sum   = 0.0;
        for ($k = 0; $k < $steps; ++$k) {
            $theta = \M_PI * ($k + 0.5) / $steps;
            $sum += cos($order * $theta - $x * sin($theta));
        }

        return $sum / $steps;
    }

    private static function j0(float $x): float
    {
        return self::besselOrLimit(0, $x);
    }

    private static function besselOrLimit(int $order, float $x): float
    {
        if (!is_finite($x)) {
            return is_nan($x) ? $x : 0.0;
        }

        $magnitude = abs($x);
        if ($magnitude < self::ASYMPTOTIC_FROM || ($magnitude < self::INTEGRAL_UP_TO && $order * $order > $magnitude)) {
            return self::besselIntegral($order, $x);
        }

        $sign = $x < 0 && 1 === $order % 2 ? -1.0 : 1.0;

        return $sign * self::hankel($order, abs($x), false);
    }

    /**
     * The large-argument expansion of J_n(x) (or Y_n(x) when $second) for x >= 25: the leading terms of the
     * Hankel series, with the phase taken from sin x and cos x so that huge arguments reduce exactly.
     */
    private static function hankel(int $order, float $x, bool $second): float
    {
        $mu       = 4.0 * $order * $order;
        $eight    = 8.0 * $x;
        $even     = 1.0;
        $odd      = 0.0;
        $term     = 1.0;
        $previous = \INF;
        for ($k = 1; $k <= 40; ++$k) {
            $term *= ($mu - (2 * $k - 1) ** 2) / ($k * $eight);
            if (abs($term) >= $previous || abs($term) < 1e-18) {
                break;
            }

            $previous = abs($term);
            if (1 === $k % 2) {
                $odd += 1 === $k % 4 ? $term : -$term;
            } else {
                $even += 2 === $k % 4 ? -$term : $term;
            }
        }

        $shift = ($order / 2 + 0.25) * \M_PI;
        $cos   = cos($x)             * cos($shift) + sin($x) * sin($shift);
        $sin   = sin($x)             * cos($shift) - cos($x) * sin($shift);
        $scale = sqrt(2 / (\M_PI * $x));

        return $second
            ? $scale * ($even * $sin + $odd * $cos)
            : $scale * ($even * $cos - $odd * $sin);
    }

    private static function j1(float $x): float
    {
        return self::besselOrLimit(1, $x);
    }

    private static function jn(int $order, float $x): float
    {
        if ($order < 0) {
            return (0 === $order % 2 ? 1 : -1) * self::besselOrLimit(-$order, $x);
        }

        return self::besselOrLimit($order, $x);
    }

    /**
     * The Bessel Y functions' value outside the finite positive reals, or null inside them.
     */
    private static function yOutsideDomain(float $x): ?float
    {
        if ($x < 0) {
            return \NAN;
        }

        if (0.0 === $x) {
            return -\INF;
        }

        if (!is_finite($x)) {
            return is_nan($x) ? $x : 0.0;
        }

        return null;
    }

    private static function y0(float $x): float
    {
        $edge = self::yOutsideDomain($x);
        if (null !== $edge) {
            return $edge;
        }

        if ($x < 8.0) {
            $y = $x                 * $x;
            $a = -2957821389.0 + $y * (7062834065.0 + $y * (-512359803.6 + $y * (10879881.29 + $y * (-86327.92757 + $y * 228.4622733))));
            $b = 40076544269.0 + $y * (745249964.8 + $y * (7189466.438 + $y * (47447.26470 + $y * (226.1030244 + $y))));

            return $a / $b + 0.636619772 * self::j0($x) * log($x);
        }

        return self::yLarge(0, $x, 0.785398164, self::Y0_COSINE_TERMS, ...self::Y0_SINE_TERMS);
    }

    /**
     * Y of the given order for x from 8 up: the Hankel expansion past ASYMPTOTIC_FROM, otherwise the
     * Numerical Recipes rational form with the given phase and Horner coefficients in (8/x)^2.
     *
     * @param list<float> $cosineTerms
     */
    private static function yLarge(int $order, float $x, float $phase, array $cosineTerms, float ...$sineTerms): float
    {
        if ($x >= self::ASYMPTOTIC_FROM) {
            return self::hankel($order, $x, true);
        }

        $z  = 8.0 / $x;
        $y  = $z * $z;
        $xx = $x - $phase;
        $a  = self::horner($y, ...$cosineTerms);
        $b  = self::horner($y, ...$sineTerms);

        return sqrt(0.636619772 / $x) * (sin($xx) * $a + $z * cos($xx) * $b);
    }

    /**
     * @param float ...$coefficients lowest power first
     */
    private static function horner(float $y, float ...$coefficients): float
    {
        $coefficients = array_values($coefficients);
        $sum          = 0.0;
        for ($i = \count($coefficients) - 1; $i >= 0; --$i) {
            $sum = $coefficients[$i] + $y * $sum;
        }

        return $sum;
    }

    private static function y1(float $x): float
    {
        $edge = self::yOutsideDomain($x);
        if (null !== $edge) {
            return $edge;
        }

        if ($x < 8.0) {
            $y = $x                   * $x;
            $a = $x                   * (-0.4900604943e13 + $y * (0.1275274390e13 + $y * (-0.5153438139e11 + $y * (0.7349264551e9 + $y * (-0.4237922726e7 + $y * 0.8511937935e4)))));
            $b = 0.2499580570e14 + $y * (0.4244419664e12 + $y * (0.3733650367e10 + $y * (0.2245904002e8 + $y * (0.1020426050e6 + $y * (0.3549632885e3 + $y)))));

            return $a / $b + 0.636619772 * (self::j1($x) * log($x) - 1.0 / $x);
        }

        return self::yLarge(1, $x, 2.356194491, self::Y1_COSINE_TERMS, ...self::Y1_SINE_TERMS);
    }

    private static function yn(int $order, float $x): float
    {
        if ($order < 0) {
            return (0 === $order % 2 ? 1 : -1) * self::yn(-$order, $x);
        }

        if (0 === $order) {
            return self::y0($x);
        }

        $previous = self::y0($x);
        $current  = self::y1($x);
        for ($k = 1; $k < $order; ++$k) {
            $next     = 2 * $k / $x * $current - $previous;
            $previous = $current;
            $current  = $next;
        }

        return $current;
    }
}
