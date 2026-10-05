<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Edges of join, ascii, isnormal, contains on booleans and string slices.
 *
 * @internal
 */
final class CoreEdgesTest extends TestCase
{
    private const string ASCII_RANGE = 'ascii only takes numbers between 0 and 127';

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('values')]
    public function testValues(string $name, mixed $input, array $args, mixed $expected): void
    {
        self::assertSame($expected, Harness::call($name, $input, $args), $name);
    }

    /**
     * @return iterable<string, array{string, mixed, list<mixed>, mixed}>
     */
    public static function values(): iterable
    {
        $table = [
            'join'     => [
                [[1, 'x', null, true, false, 2.5], [','], '1,x,,true,false,2.5'],
                [[], [','], ''],
                [['a'], [','], 'a'],
            ],
            'ascii'    => [[0, [], "\x00"], [127, [], "\x7f"]],
            'isnormal' => [
                [\PHP_FLOAT_MIN, [], true],
                [-\PHP_FLOAT_MIN, [], true],
                [\PHP_FLOAT_MIN / 2, [], false],
            ],
            'contains' => [
                [true, [true], true],
                [[true, false], [[false]], true],
                [[true], [[false]], false],
            ],
        ];

        foreach ($table as $name => $cases) {
            foreach ($cases as $index => [$input, $args, $expected]) {
                yield $name . ' ' . $index => [$name, $input, $args, $expected];
            }
        }
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('errors')]
    public function testErrors(string $name, mixed $input, array $args, string $message): void
    {
        self::assertSame($message, Harness::error($name, $input, $args), $name);
    }

    /**
     * @return iterable<string, array{string, mixed, list<mixed>, string}>
     */
    public static function errors(): iterable
    {
        $table = [
            'join'     => [
                [['a', 'b'], [1], 'string ("a") and number (1) cannot be added'],
                [[[1], [2]], [','], 'string ("") and array ([1]) cannot be added'],
                [['a', [1]], [','], 'string ("a,") and array ([1]) cannot be added'],
            ],
            'ascii'    => [[-1, [], self::ASCII_RANGE], [128, [], self::ASCII_RANGE]],
            'contains' => [
                [true, [false], 'boolean (true) and boolean (false) cannot have their containment checked'],
                [false, [true], 'boolean (false) and boolean (true) cannot have their containment checked'],
            ],
        ];

        foreach ($table as $name => $cases) {
            foreach ($cases as $index => [$input, $args, $message]) {
                yield $name . ' ' . $index => [$name, $input, $args, $message];
            }
        }
    }
}
