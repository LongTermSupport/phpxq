<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\Codec\NumberFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NumberFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{float, string}>
     */
    public static function floatProvider(): iterable
    {
        yield 'zero'               => [0.0, '0'];
        yield 'negative zero'     => [-0.0, '-0'];
        yield 'one'                => [1.0, '1'];
        yield 'integral'           => [100.0, '100'];
        yield 'negative integral'  => [-3.0, '-3'];
        yield 'simple fraction'    => [1.5, '1.5'];
        yield 'point one'          => [0.1, '0.1'];
        yield 'third'              => [1 / 3, '0.3333333333333333'];
        yield 'seventeen digits'   => [0.1 + 0.2, '0.30000000000000004'];
        yield 'small plain'        => [0.0001, '0.0001'];
        yield 'small exponent'     => [0.00001, '1e-05'];
        yield 'tiny'               => [1.5e-10, '1.5e-10'];
        yield 'two digit exponent' => [1e-7, '1e-07'];
        yield 'big integral'       => [1e15, '1000000000000000'];
        yield 'sixteen digits'     => [1e16, '1e+16'];
        yield 'beyond 2^53'        => [9007199254740993.0, '9007199254740992'];
        yield 'seventeen digit integral' => [13911860366432392.0, '13911860366432392'];
        yield 'one e17'            => [1e17, '1e+17'];
        yield 'big'                => [1.5e300, '1.5e+300'];
        yield 'integral digits'    => [123456789012345680.0, '123456789012345680'];
        yield 'max double'         => [\PHP_FLOAT_MAX, '1.7976931348623157e+308'];
        yield 'min subnormal'      => [5e-324, '5e-324'];
        yield 'pi'                 => [3.141592653589793, '3.141592653589793'];
        yield 'negative fraction'  => [-2.5e-5, '-2.5e-05'];
    }

    #[DataProvider('floatProvider')]
    public function testFormatsLikeJq(float $value, string $expected): void
    {
        self::assertSame($expected, NumberFormatter::format($value));
    }

    public function testRandomDoublesRoundTripAndUseJqLayout(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 20000; ++$i) {
            $unpacked = unpack('d', pack('NN', mt_rand(0, 0xFFFFFFFF), mt_rand(0, 0xFFFFFFFF)));
            self::assertIsArray($unpacked);
            $value = (float)$unpacked[1];
            if (is_nan($value) || is_infinite($value)) {
                continue;
            }

            $text = NumberFormatter::format($value);

            self::assertSame($value, (float)$text, $text);
            self::assertMatchesRegularExpression('/^-?(\d+(\.\d+)?|\d(\.\d+)?e[+-]\d{2,3})$/', $text);
            self::assertDoesNotMatchRegularExpression('/\.0$|\.0e/', $text);
        }

        for ($i = 0; $i < 20000; ++$i) {
            $value = mt_rand() / mt_rand(1, 1000000) * (0 === $i % 2 ? 1 : 1.0e-7);
            $text  = NumberFormatter::format($value);

            self::assertSame($value, (float)$text, $text);
        }
    }

    public function testNanIsNull(): void
    {
        self::assertSame('null', NumberFormatter::format(\NAN));
    }

    public function testInfinitiesClampToLargestDouble(): void
    {
        self::assertSame('1.7976931348623157e+308', NumberFormatter::format(\INF));
        self::assertSame('-1.7976931348623157e+308', NumberFormatter::format(-\INF));
    }
}
