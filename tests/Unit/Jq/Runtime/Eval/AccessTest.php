<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Access;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Access::class)]
final class AccessTest extends TestCase
{
    use AssertsRaised;

    public function testObjectMembers(): void
    {
        $object = new JsonObject(['a' => 1]);

        self::assertSame(1, Access::index($object, 'a'));
        self::assertNull(Access::index($object, 'missing'));
    }

    public function testNullIndexesToNull(): void
    {
        self::assertNull(Access::index(null, 'a'));
        self::assertNull(Access::index(null, 0));
        self::assertNull(Access::index(null, 1.5));
        self::assertNull(Access::index(null, new JsonObject(['start' => 1])));
        self::assertNull(Access::index(null, null));
    }

    public function testArrayPositions(): void
    {
        $array = [10, 20, 30];

        self::assertSame(10, Access::index($array, 0));
        self::assertSame(30, Access::index($array, -1));
        self::assertNull(Access::index($array, 3));
        self::assertNull(Access::index($array, -4));
    }

    public function testFractionalPositionsAreFloored(): void
    {
        $array = [10, 20, 30];

        self::assertSame(20, Access::index($array, 1.7));
        self::assertSame(20, Access::index($array, 1.1));
        self::assertSame(20, Access::index($array, new PreciseNumber(1.5, '1.5')));
        self::assertNull(Access::index($array, \NAN));
    }

    public function testSliceObjectsAsKeys(): void
    {
        self::assertSame([20, 30], Access::index([10, 20, 30], new JsonObject(['start' => 1, 'end' => null])));
        self::assertSame('bc', Access::index('abcd', new JsonObject(['start' => 1, 'end' => 3])));
    }

    public function testArrayKeysFindSubarrays(): void
    {
        self::assertSame([0, 2], Access::index([1, 2, 1, 2], [1, 2]));
        self::assertSame([1], Access::index([0, 1, 2], [1]));
        self::assertNull(Access::index([1], []));
    }

    /**
     * @param list<mixed> $haystack
     * @param list<mixed> $needle
     * @param ?list<int>  $expected
     */
    #[DataProvider('subarrays')]
    public function testIndices(array $haystack, array $needle, ?array $expected): void
    {
        self::assertSame($expected, Access::indices($haystack, $needle));
    }

    /**
     * @return iterable<string, array{list<mixed>, list<mixed>, ?list<int>}>
     */
    public static function subarrays(): iterable
    {
        yield 'two matches'   => [[1, 2, 1, 2, 1], [1, 2], [0, 2]];
        yield 'overlapping'   => [[1, 1, 1], [1, 1], [0, 1]];
        yield 'no match'      => [[1, 2], [3], []];
        yield 'empty needle'  => [[1, 2], [], null];
        yield 'needle longer' => [[1], [1, 2], []];
    }

    #[DataProvider('invalidIndexes')]
    public function testIndexErrors(mixed $target, mixed $key, string $message): void
    {
        self::assertRaises(JqException::class, $message, static fn (): mixed => Access::index($target, $key));
    }

    /**
     * @return iterable<string, array{mixed, mixed, string}>
     */
    public static function invalidIndexes(): iterable
    {
        yield 'number by string'   => [1, 'a', 'Cannot index number with string ("a")'];
        yield 'array by string'    => [[1], 'a', 'Cannot index array with string ("a")'];
        yield 'object by number'   => [new JsonObject([]), 0, 'Cannot index object with number (0)'];
        yield 'string by number'   => ['abc', 1.5, 'Cannot index string with number (1.5)'];
        yield 'bool by number'     => [true, 0, 'Cannot index boolean with number (0)'];
        yield 'object by null'     => [new JsonObject([]), null, 'Cannot index object with null (null)'];
        yield 'number by slice'    => [1, new JsonObject(['start' => 1]), 'Cannot index number with object ({"start":1})'];
        yield 'object by array'    => [new JsonObject([]), [1], 'Cannot index object with array ([1])'];
    }

    public function testSlicesArraysAndStrings(): void
    {
        self::assertSame([1, 2], Access::slice([0, 1, 2, 3], 1, 3));
        self::assertSame([2, 3], Access::slice([0, 1, 2, 3], -2, null));
        self::assertSame('bc', Access::slice('abcd', 1, 3));
        self::assertSame('é', Access::slice('aéb', 1, 2));
        self::assertNull(Access::slice(null, 1, 2));
    }

    public function testSliceRejectsOtherTypes(): void
    {
        $this->expectException(JqException::class);

        Access::slice(5, 1, 2);
    }

    /**
     * @param array{int, int} $expected
     */
    #[DataProvider('bounds')]
    public function testBounds(int $length, mixed $from, mixed $to, array $expected): void
    {
        self::assertSame($expected, Access::bounds($length, $from, $to));
    }

    /**
     * @return iterable<string, array{int, mixed, mixed, array{int, int}}>
     */
    public static function bounds(): iterable
    {
        yield 'plain'              => [5, 1, 3, [1, 3]];
        yield 'open'               => [5, null, null, [0, 5]];
        yield 'negative'           => [5, -2, null, [3, 5]];
        yield 'start floors'       => [10, 1.7, 3.5, [1, 4]];
        yield 'end past length'    => [3, 1, 99, [1, 3]];
        yield 'start past length'  => [3, 99, null, [3, 3]];
        yield 'reversed'           => [5, 4, 1, [4, 4]];
        yield 'nan start'          => [3, \NAN, 1, [0, 1]];
        yield 'nan end'            => [3, 1, \NAN, [1, 3]];
        yield 'far negative end'   => [10, 1.7, -4294967296, [1, 1]];
        yield 'huge end'           => [10, 1.7, 4294967295, [1, 10]];
        yield 'precise number'     => [5, new PreciseNumber(1.0, '1.0'), 2, [1, 2]];
    }

    public function testBoundsRejectNonNumbers(): void
    {
        self::assertRaises(JqException::class, 'Start and end indices of an array slice must be numbers', static fn (): mixed => Access::bounds(3, 'a', null));
    }

    public function testPosition(): void
    {
        self::assertSame(1, Access::position(1.9));
        self::assertSame(-2, Access::position(-1.1));
        self::assertNull(Access::position(\NAN));
        self::assertSame(4294967296, Access::position(1e30));
    }
}
