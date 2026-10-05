<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Yaml\Parser\ScalarResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every expectation was checked against the reference yq (mikefarah v4.54.1, go-yaml).
 *
 * @internal
 */
final class ScalarResolverTest extends TestCase
{
    #[DataProvider('tagProvider')]
    public function testResolve(string $plain, string $tag): void
    {
        self::assertSame($tag, ScalarResolver::resolve($plain));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tagProvider(): iterable
    {
        $table = [
            '!!null'      => ['', '~', 'null', 'Null', 'NULL'],
            '!!bool'      => ['true', 'True', 'TRUE', 'false', 'False', 'FALSE'],
            '!!int'       => ['0', '12', '+5', '-5', '0777', '0o17', '0b101', '0B11', '0x1F', '0X1f', '-0x1f', '+0x1f', '1_000', '1__0', '0x_1', '1_', '0_7', '-00', '-0x0', '+0',
                '9223372036854775807', '9223372036854775808', '18446744073709551615', '-9223372036854775808', '0xFFFFFFFFFFFFFFFF', '-0x8000000000000000', '0x8000000000000000'],
            '!!float'     => ['1.5', '.5', '5.', '1e3', '1E3', '1e+3', '.1e2', '-.5', '08', '0.0', '-0.0', '-0', '1_0.5', '.inf', '.Inf', '.INF', '+.inf', '-.inf', '.nan', '.NaN', '.NAN',
                '18446744073709551616', '-9223372036854775809', '+18446744073709551615'],
            '!!str'       => ['yes', 'no', 'on', 'off', 'y', 'n', 'tRUE', 'a', 'e3', '1e', '0o', '0x', '_1', '1,000', '12:30:45', '-', '+', '.', '1e400', '-0x8000000000000001', '0x10000000000000000', '+.INf', 'Inf', 'nan'],
            '!!timestamp' => ['2001-12-14', '2001-2-3', '2000-02-29', '0001-01-01', '2001-12-14T21:59:43Z', '2001-12-14t1:2:3+01:00', '2001-12-14T21:59:43.10-05:00', '2001-12-14T21:59:43,5Z', '2001-12-14 21:59:43', '2001-12-14T23:59:59+24:00'],
            '!!merge'     => ['<<'],
        ];

        foreach ($table as $tag => $values) {
            foreach ($values as $value) {
                yield $tag . ' ' . $value => [$value, $tag];
            }
        }

        $extra = [
            // Octal and binary limits are compared after stripping leading zeros.
            '!!int'       => [
                '01777777777777777777777', '0001777777777777777777777', '+0777777777777777777777', '-01000000000000000000000',
                '0b' . str_repeat('1', 64), '0b0' . str_repeat('1', 64), '+0b' . str_repeat('1', 63), '-0b1' . str_repeat('0', 63),
                '0o1777777777777777777777', '0o0001777777777777777777777', '+0o777777777777777777777', '-0o1000000000000000000000',
            ],
            '!!float'     => ['02000000000000000000000', '-01000000000000000000001'],
            '!!str'       => [
                '0b1' . str_repeat('1', 64), '+0b1' . str_repeat('0', 63), '-0b1' . str_repeat('0', 62) . '1', '0b' . str_repeat('0', 3) . '2',
                '0o2000000000000000000000', '+0o1000000000000000000000', '-0o1000000000000000000001',
            ],
            '!!timestamp' => ['2004-02-29', '2000-02-29', '2400-02-29', '2001-02-28', '2001-04-30', '2001-01-31', '2001-12-31', '2001-11-30', '2001-06-30', '2001-09-30'],
        ];
        foreach ($extra as $tag => $values) {
            foreach ($values as $value) {
                yield 'extra ' . $tag . ' ' . $value => [$value, $tag];
            }
        }

        foreach (['2001-02-29', '2003-02-29', '2100-02-29', '2000-02-30', '2001-04-31', '2001-06-31', '2001-09-31', '2001-11-31', '2001-01-32', '2001-13-01', '2001-00-10', '2001-01-00', '2001-02-30', '1900-02-29', '2001-12-14T21:59:43', '2001-12-14 21:59', '2001-12-14T25:00:00Z', '2001-12-14T23:59:60Z', '2001-12-14T23:59:59+0100', '12345-1-1', '2001-12-14T21:59:43.10-05:00x', '2001-12-14 21:59:43.10 -5'] as $value) {
            yield 'not a timestamp ' . $value => [$value, '!!str'];
        }
    }
}
