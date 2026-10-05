<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\Unicode;
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

    #[DataProvider('slices')]
    public function testSlice(string $text, int $from, int $to, string $expected): void
    {
        self::assertSame($expected, Unicode::slice($text, $from, $to));
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function slices(): iterable
    {
        yield 'the whole ascii text' => ['hello', 0, 5, 'hello'];
        yield 'the whole multibyte text' => ['héllo', 0, 5, 'héllo'];
        yield 'an ascii prefix' => ['world', 0, 3, 'wor'];
        yield 'an ascii suffix' => ['abc', 1, 3, 'bc'];
        yield 'an ascii middle' => ['yellow', 1, 3, 'el'];
        yield 'a multibyte middle' => ['wörld', 1, 3, 'ör'];
        yield 'a multibyte prefix' => ['naïve', 0, 3, 'naï'];
        yield 'a multibyte suffix' => ['çava', 1, 4, 'ava'];
        yield 'a negative start is clamped' => ['plane', -5, 2, 'pl'];
        yield 'an end past the text is clamped' => ['stone', 1, 99, 'tone'];
        yield 'an end before the start is empty' => ['truck', 2, 1, ''];
        yield 'an empty text' => ['', 0, 0, ''];
        yield 'a start past the text' => ['car', 7, 9, ''];
    }
}
