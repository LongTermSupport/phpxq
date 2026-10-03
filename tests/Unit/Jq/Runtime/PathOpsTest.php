<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathOps;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PathOps::class)]
final class PathOpsTest extends TestCase
{
    use AssertsRaised;

    public function testGetPath(): void
    {
        $value = new JsonObject(['foo' => ['a', 'b', 'c']]);

        self::assertSame('b', PathOps::getPath($value, ['foo', 1]));
        self::assertSame($value, PathOps::getPath($value, []));
        self::assertNull(PathOps::getPath($value, ['bar']));
        self::assertNull(PathOps::getPath($value, ['bar', 'x', 0]));
        self::assertSame('c', PathOps::getPath($value, ['foo', -1]));
    }

    public function testGetPathWithSliceKeys(): void
    {
        $slice = new JsonObject(['start' => 1, 'end' => 3]);

        self::assertSame(['b', 'c'], PathOps::getPath(['a', 'b', 'c', 'd'], [$slice]));
        self::assertSame('bc', PathOps::getPath('abcd', [$slice]));
    }

    public function testGetPathPropagatesTypeErrors(): void
    {
        self::assertRaises(JqException::class, 'Cannot index number with string ("b")', static fn (): mixed => PathOps::getPath(new JsonObject(['a' => 1]), ['a', 'b']));
    }

    /**
     * @param list<mixed> $path
     */
    #[DataProvider('sets')]
    public function testSetPath(mixed $value, array $path, mixed $new, mixed $expected): void
    {
        self::assertEquals($expected, PathOps::setPath($value, $path, $new));
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>, mixed, mixed}>
     */
    public static function sets(): iterable
    {
        yield 'empty path'          => [1, [], 2, 2];
        yield 'object key'          => [new JsonObject(['a' => 1]), ['a'], 2, new JsonObject(['a' => 2])];
        yield 'new key'             => [new JsonObject(['a' => 1]), ['b'], 2, new JsonObject(['a' => 1, 'b' => 2])];
        yield 'null becomes object' => [null, ['a', 'b'], 1, new JsonObject(['a' => new JsonObject(['b' => 1])])];
        yield 'null becomes array'  => [null, [2], 1, [null, null, 1]];
        yield 'array element'       => [[0, 1], [1], 9, [0, 9]];
        yield 'negative index'      => [[0], [-1], 1, [1]];
        yield 'pads with null'      => [[4], [3], 1, [4, null, null, 1]];
        yield 'nested'              => [[4], [2, 3], 1, [4, null, [null, null, null, 1]]];
        yield 'fractional index'    => [[0, 1, 2, 3, 4], [1.1], 5, [0, 5, 2, 3, 4]];
        yield 'precise index'       => [[0, 1], [new PreciseNumber(1.0, '1.0')], 5, [0, 5]];
        yield 'slice replacement'   => [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [new JsonObject(['start' => 1.5, 'end' => 3.5])], ['xyz'], [0, 'xyz', 4, 5, 6, 7, 8, 9]];
        yield 'slice on null'       => [null, [new JsonObject(['start' => 0, 'end' => 1])], [1], [1]];
        yield 'numeric key'         => [new JsonObject([]), ['1'], 1, new JsonObject(['1' => 1])];
    }

    /**
     * @param list<mixed> $path
     */
    #[DataProvider('failingSets')]
    public function testSetPathErrors(mixed $value, array $path, mixed $new, string $message): void
    {
        self::assertRaises(JqException::class, $message, static fn (): mixed => PathOps::setPath($value, $path, $new));
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>, mixed, string}>
     */
    public static function failingSets(): iterable
    {
        yield 'object by number'  => [new JsonObject(['hi' => 'hello']), [1], 1, 'Cannot index object with number (1)'];
        yield 'nan index'         => [[0], [\NAN], 1, 'Cannot set array element at NaN index'];
        yield 'negative range'    => [[0], [-5], 1, 'Out of bounds negative array index'];
        yield 'string slice'      => ['foobar', [new JsonObject(['start' => 1, 'end' => 2])], 'x', 'Cannot update string slices'];
        yield 'slice value'       => [[1, 2], [new JsonObject(['start' => 0, 'end' => 1])], 5, 'A slice of an array can only be assigned another array'];
        yield 'array by array'    => [[], [[1]], 1, 'Cannot update field at array index of array'];
        yield 'too large'         => [[], [536870912], 1, 'Array index too large'];
        yield 'scalar by string'  => [5, ['a'], 1, 'Cannot index number with string ("a")'];
    }

    public function testSetKeyOnStringSlicesIsRefused(): void
    {
        self::assertRaises(JqException::class, 'Cannot update string slices', static fn (): mixed => PathOps::setKey('foobar', new JsonObject(['start' => 1, 'end' => 3]), 'xyz'));
    }

    /**
     * @param list<list<mixed>> $paths
     */
    #[DataProvider('deletions')]
    public function testDeletePaths(mixed $value, array $paths, mixed $expected): void
    {
        self::assertEquals($expected, PathOps::deletePaths($value, $paths));
    }

    /**
     * @return iterable<string, array{mixed, list<list<mixed>>, mixed}>
     */
    public static function deletions(): iterable
    {
        yield 'no paths'             => [[1, 2], [], [1, 2]];
        yield 'root'                 => [[1, 2], [[]], null];
        yield 'object key'           => [new JsonObject(['a' => 1, 'b' => 2]), [['a']], new JsonObject(['b' => 2])];
        yield 'array index'          => [[1, 2, 3], [[1]], [1, 3]];
        yield 'several indexes'      => [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [[1], [-6], [2], [new JsonObject(['start' => -3, 'end' => 9])]], [0, 3, 5, 6, 9]];
        yield 'nested'               => [[[0, 1], [2, 3]], [[0, 1], [1, 0]], [[0], [3]]];
        yield 'out of range'         => [[1, 2, 3], [[-200]], [1, 2, 3]];
        yield 'nan'                  => [[1, 2, 3], [[\NAN]], [1, 2, 3]];
        yield 'missing parent'       => [new JsonObject(['a' => 1]), [['x', 'y']], new JsonObject(['a' => 1])];
        yield 'null parent'          => [new JsonObject(['x' => null]), [['x', 'y']], new JsonObject(['x' => null])];
        yield 'on null'              => [null, [['a']], null];
        yield 'child of deleted'     => [new JsonObject(['a' => new JsonObject(['b' => 1])]), [['a'], ['a', 'b']], new JsonObject([])];
        yield 'object with slice'    => [[1, 2, 3, 4], [[new JsonObject(['start' => 1, 'end' => 3])]], [1, 4]];
        yield 'in object in array'   => [[new JsonObject(['foo' => 2, 'x' => 1])], [[0, 'foo']], [new JsonObject(['x' => 1])]];
    }

    /**
     * @param list<mixed> $paths
     */
    #[DataProvider('failingDeletions')]
    public function testDeletePathsErrors(mixed $value, array $paths, string $message): void
    {
        self::assertRaises(JqException::class, $message, static fn (): mixed => PathOps::deletePaths($value, $paths));
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>, string}>
     */
    public static function failingDeletions(): iterable
    {
        yield 'path is not an array'   => [[1], ['a'], 'Path must be specified as an array'];
        yield 'string key on array'    => [[1], [['a']], 'Cannot delete field at object index of array'];
        yield 'number key on object'   => [new JsonObject(['a' => 1]), [[0]], 'Cannot delete field at array index of object'];
        yield 'scalar'                 => [5, [['a']], 'Cannot delete fields from number'];
    }
}
