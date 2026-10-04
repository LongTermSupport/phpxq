<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\CollectionFunctions;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CollectionFunctionsTest extends TestCase
{
    public function testSortUsesJqOrdering(): void
    {
        $input    = Harness::json('[42,[2,5,3,11],10,{"a":42,"b":2},{"a":42},true,2,[2,6],"hello",null,[2,5,6],{"a":[],"b":1},"abc","ab",[3,10],{},false,"abcd",null]');
        $expected = Harness::json('[null,null,false,true,2,10,42,"ab","abc","abcd","hello",[2,5,3,11],[2,5,6],[2,6],[3,10],{},{"a":42},{"a":42,"b":2},{"a":[],"b":1}]');

        self::assertEquals($expected, Harness::call('sort', $input));
    }

    public function testSortFastPaths(): void
    {
        self::assertSame([1, 2.5, 3, 10], Harness::call('sort', [10, 3, 2.5, 1]));
        self::assertSame(['a', 'ab', 'abc', 'b'], Harness::call('sort', ['abc', 'b', 'a', 'ab']));
        self::assertSame(['', 'a', 'ab', 'b', "\u{3bc}"], Harness::call('sort', ["\u{3bc}", 'b', 'ab', 'a', '']));
        self::assertSame([], Harness::call('sort', []));
        self::assertSame([1], Harness::call('sort', [1]));
    }

    public function testSortPutsNanFirstAmongNumbers(): void
    {
        $sorted = Harness::call('sort', [3, \NAN, 1]);

        self::assertIsArray($sorted);
        self::assertNan($sorted[0]);
        self::assertSame([1, 3], \array_slice($sorted, 1));
    }

    public function testSortNumericStrings(): void
    {
        self::assertSame(['10', '9'], Harness::call('sort', ['9', '10']));
    }

    public function testSortRejectsNonArrays(): void
    {
        self::assertSame('string ("a") cannot be sorted, as it is not an array', Harness::error('sort', 'a'));
        self::assertSame('object ({}) cannot be sorted, as it is not an array', Harness::error('unique', new JsonObject()));
    }

    public function testSortOfTooDeepValuesFails(): void
    {
        $deep = [];
        for ($i = 0; $i < 10001; ++$i) {
            $deep = [$deep];
        }

        self::assertSame('Comparison too deep', Harness::error('sort', [$deep, $deep]));
        self::assertSame('Comparison too deep', Harness::error('unique', [$deep, $deep]));
    }

    public function testUnique(): void
    {
        self::assertSame([1, 2, 3, 5], Harness::call('unique', [1, 2, 5, 3, 5, 3, 1, 3]));
        self::assertSame([], Harness::call('unique', []));
        self::assertEquals(Harness::json('[[1],[2]]'), Harness::call('unique', Harness::json('[[2],[1],[2]]')));
    }

    public function testSortByKeepsOrderOfEqualKeys(): void
    {
        $values = Harness::json('[{"a":1,"i":0},{"a":0,"i":1},{"a":1,"i":2},{"a":0,"i":3}]');
        $keys   = [[1], [0], [1], [0]];

        self::assertEquals(
            Harness::json('[{"a":0,"i":1},{"a":0,"i":3},{"a":1,"i":0},{"a":1,"i":2}]'),
            Harness::call('_sort_by_impl', $values, [$keys]),
        );
    }

    public function testSortByMultipleKeys(): void
    {
        self::assertSame(['c', 'b', 'a'], Harness::call('_sort_by_impl', ['a', 'b', 'c'], [[[2, 1], [1, 5], [1, 2]]]));
        self::assertSame(['b', 'c', 'a'], Harness::call('_sort_by_impl', ['a', 'b', 'c'], [[[2, 1], [1, 2], [1, 5]]]));
    }

    public function testSortByRequiresMatchingArrays(): void
    {
        self::assertSame(
            'number (1) and array ([]) cannot be sorted, as they are not both arrays',
            Harness::error('_sort_by_impl', 1, [[]]),
        );
        self::assertSame(
            'array ([1]) and array ([]) cannot be sorted, as they are not both arrays',
            Harness::error('_sort_by_impl', [1], [[]]),
        );
    }

    public function testGroupBy(): void
    {
        $values = ['x', 'y', 'z', 'w'];
        $keys   = [[1], [3], [1], [2]];

        self::assertSame([['x', 'z'], ['w'], ['y']], Harness::call('_group_by_impl', $values, [$keys]));
        self::assertSame([], Harness::call('_group_by_impl', [], [[]]));
        self::assertSame(
            'number (1) and null (null) cannot be grouped, as they are not both arrays',
            Harness::error('_group_by_impl', 1, [null]),
        );
    }

    public function testUniqueBy(): void
    {
        self::assertSame(['x', 'w', 'y'], Harness::call('_unique_by_impl', ['x', 'y', 'z', 'w'], [[[1], [3], [1], [2]]]));
        self::assertSame([], Harness::call('_unique_by_impl', [], [[]]));
    }

    public function testMinAndMax(): void
    {
        self::assertSame(2, Harness::call('min', [5, 4, 2, 7]));
        self::assertSame(7, Harness::call('max', [5, 4, 2, 7]));
        self::assertNull(Harness::call('min', []));
        self::assertNull(Harness::call('max', []));
        self::assertNull(Harness::call('min', [null, 1]));
        self::assertSame('x', Harness::call('max', [1, 'x', null]));
    }

    public function testMinByTakesTheFirstAndMaxByTheLast(): void
    {
        $values = [[4, 2, 'a'], [3, 1, 'a'], [2, 4, 'a'], [1, 3, 'a']];
        $keys   = [[2], [1], [4], [3]];
        self::assertSame([3, 1, 'a'], Harness::call('_min_by_impl', $values, [$keys]));
        self::assertSame([2, 4, 'a'], Harness::call('_max_by_impl', $values, [$keys]));

        $ties = [[1], [1], [1]];
        self::assertSame('a', Harness::call('_min_by_impl', ['a', 'b', 'c'], [$ties]));
        self::assertSame('c', Harness::call('_max_by_impl', ['a', 'b', 'c'], [$ties]));
    }

    public function testMinByValidation(): void
    {
        self::assertSame('number (1) and number (1) cannot be iterated over', Harness::error('min', 1));
        self::assertSame('array ([1]) and array ([]) have wrong length', Harness::error('_min_by_impl', [1], [[]]));
    }

    public function testReverse(): void
    {
        self::assertSame([3, 2, 1], Harness::call('reverse', [1, 2, 3]));
        self::assertSame('cba', Harness::call('reverse', 'abc'));
        self::assertSame("\u{1f600}\u{3bc}a", Harness::call('reverse', "a\u{3bc}\u{1f600}"));
        self::assertSame([], Harness::call('reverse', null));
        self::assertSame([], Harness::call('reverse', new JsonObject()));
        self::assertSame('Cannot index number with number (4)', Harness::error('reverse', 5));
        self::assertSame('boolean (true) has no length', Harness::error('reverse', true));
    }

    public function testFlatten(): void
    {
        $nested = Harness::json('[0,[1],[[2]],[[[3]]]]');

        self::assertSame([0, 1, 2, 3], Harness::call('flatten', $nested));
        self::assertEquals($nested, Harness::call('flatten', $nested, [0]));
        self::assertEquals(Harness::json('[0,1,2,[3]]'), Harness::call('flatten', $nested, [2]));
        self::assertSame([], Harness::call('flatten', Harness::json('[[]]')));
        self::assertEquals(Harness::json('[{"foo":"bar"},{"foo":"baz"}]'), Harness::call('flatten', Harness::json('[{"foo":"bar"},[{"foo":"baz"}]]')));
        self::assertSame([1, 2], Harness::call('flatten', Harness::json('{"a":1,"b":[2]}')));
    }

    public function testFlattenValidation(): void
    {
        self::assertSame('flatten depth must not be negative', Harness::error('flatten', [], [-1]));
        self::assertSame('flatten depth must not be negative', Harness::error('flatten', [], ['a']));
        self::assertSame('Cannot iterate over number (5)', Harness::error('flatten', 5));
    }

    public function testAddFastPaths(): void
    {
        self::assertSame('abc', Harness::call('add', ['a', 'b', 'c']));
        self::assertSame('ab', Harness::call('add', ['a', null, 'b']));
        self::assertSame([3, 4, 5, 6], Harness::call('add', [[3], [4, 5], [6]]));
        self::assertNull(Harness::call('add', []));
        self::assertNull(Harness::call('add', [null, null]));
        self::assertSame(6, Harness::call('add', [1, 2, 3]));
        self::assertSame(3.5, Harness::call('add', [1, 2.5]));
        self::assertSame(4, Harness::call('add', [1.5, 2.5]));
        self::assertSame(55, Harness::call('add', Harness::json('{"a":50,"b":5}')));
    }

    public function testAddMergesObjectsLeftToRight(): void
    {
        $merged = Harness::call('add', Harness::json('[{"a":1},{"b":2},{"a":3}]'));

        self::assertInstanceOf(JsonObject::class, $merged);
        self::assertSame(['a', 'b'], $merged->keys());
        self::assertSame(3, $merged->get('a'));
        $numeric = Harness::call('add', Harness::json('[{"1":"x"},{"2":"y"},{"1":"z"}]'));
        self::assertInstanceOf(JsonObject::class, $numeric);
        self::assertSame(['1', '2'], $numeric->keys());
        self::assertSame('z', $numeric->get('1'));
    }

    public function testAddSumsBeyondTwoToThe53LikeDoubles(): void
    {
        self::assertSame(9007199254740992, Harness::call('add', [9007199254740992, 1, 1]));
        self::assertSame(9007199254740994.0, Harness::call('add', [9007199254740992, 2]));
    }

    public function testAddOfMixedKindsUsesJqArithmetic(): void
    {
        self::assertSame(
            'string ("a") and number (1) cannot be added',
            Harness::error('add', ['a', 1]),
        );
        self::assertSame('Cannot iterate over number (5)', Harness::error('add', 5));
    }

    public function testAddKeepsPreciseNumbersOutOfTheFastPath(): void
    {
        $sum = Harness::call('add', [Harness::json('100000000000000000000'), 1]);

        self::assertIsFloat($sum);
        self::assertSame(1.0e20, $sum);
    }

    public function testTranspose(): void
    {
        self::assertSame([[1, null], [null, 3]], Harness::call('transpose', [[1], [null, 3]]));
        self::assertSame([[1, 2], [null, 3]], Harness::call('transpose', [[1], [2, 3]]));
        self::assertSame([], Harness::call('transpose', []));
        self::assertSame([[1, null], [2, null]], Harness::call('transpose', [[1, 2], null]));
        self::assertSame('Cannot iterate over number (5)', Harness::error('transpose', 5));
        self::assertSame('Cannot index number with number (0)', Harness::error('transpose', [5]));
    }

    public function testBsearch(): void
    {
        self::assertSame(-1, Harness::call('bsearch', [1, 2, 3], [0]));
        self::assertSame(0, Harness::call('bsearch', [1, 2, 3], [1]));
        self::assertSame(1, Harness::call('bsearch', [1, 2, 3], [2]));
        self::assertSame(2, Harness::call('bsearch', [1, 2, 3], [3]));
        self::assertSame(-4, Harness::call('bsearch', [1, 2, 3], [4]));
        self::assertSame(-1, Harness::call('bsearch', [], [4]));
        self::assertSame(1, Harness::call('bsearch', Harness::json('[{"x":0},{"x":1},{"x":2}]'), [Harness::json('{"x":1}')]));
        self::assertSame('string ("aa") cannot be searched from', Harness::error('bsearch', 'aa', [0]));
    }

    public function testToEntries(): void
    {
        $entries = Harness::call('to_entries', Harness::json('{"b":1,"a":2,"10":3}'));

        self::assertEquals(Harness::json('[{"key":"b","value":1},{"key":"a","value":2},{"key":"10","value":3}]'), $entries);
        self::assertEquals(Harness::json('[{"key":0,"value":"x"},{"key":1,"value":"y"}]'), Harness::call('to_entries', ['x', 'y']));
        self::assertSame('number (1) has no keys', Harness::error('to_entries', 1));
    }

    public function testFromEntries(): void
    {
        $object = Harness::call('from_entries', Harness::json('[{"key":"a","value":1},{"Key":"b","Value":2},{"name":"c","value":3},{"Name":"d","Value":4},{"k":"e","v":5},{"K":"f","V":6}]'));

        self::assertInstanceOf(JsonObject::class, $object);
        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f'], $object->keys());
        self::assertSame(1, $object->get('a'));
        self::assertSame(2, $object->get('b'));
        self::assertSame(5, $object->get('e'));
        self::assertNull($object->get('f'));
    }

    public function testFromEntriesKeyTypes(): void
    {
        $object = Harness::call('from_entries', Harness::json('[{"key":1,"value":"n"},{"key":false,"value":"f"},{"key":null,"value":"z"},{"key":"s","value":null}]'));

        self::assertInstanceOf(JsonObject::class, $object);
        self::assertSame(['1', 'false', 'null', 's'], $object->keys());
        self::assertSame('f', $object->get('false'));
        self::assertTrue($object->has('s'));
    }

    public function testFromEntriesValueFalseIsKept(): void
    {
        $object = Harness::call('from_entries', Harness::json('[{"key":"a","value":false}]'));

        self::assertInstanceOf(JsonObject::class, $object);
        self::assertFalse($object->get('a'));
    }

    public function testFromEntriesLaterEntriesOverride(): void
    {
        $object = Harness::call('from_entries', Harness::json('[{"key":"a","value":1},{"key":"b","value":2},{"key":"a","value":3}]'));

        self::assertInstanceOf(JsonObject::class, $object);
        self::assertSame(['a', 'b'], $object->keys());
        self::assertSame(3, $object->get('a'));
    }

    public function testFromEntriesValidation(): void
    {
        self::assertSame('Cannot iterate over number (1)', Harness::error('from_entries', 1));
        self::assertSame('Cannot index number with string ("key")', Harness::error('from_entries', [1]));
        self::assertSame('Cannot check whether null has a string key', Harness::error('from_entries', [null]));
    }

    public function testSortByAndGroupByAgreeWithTheGenericComparisonOnAnyKeyShape(): void
    {
        mt_srand(20260);
        $pools = [
            'strings'  => static fn (): string => ['', 'a', 'B', 'ab', "\u{e9}", '10', '9'][mt_rand(0, 6)],
            'ints'     => static fn (): int => [-3, 0, 1, 2, 2, 10, 9007199254740993, -9007199254740993][mt_rand(0, 7)],
            'floats'   => static fn (): float|int => [-0.5, 0.0, 1.5, 2, 1.0E300][mt_rand(0, 4)],
            'booleans' => static fn (): ?bool => [null, false, true][mt_rand(0, 2)],
            'mixed'    => static fn (): mixed => [null, true, 3, 'x', [1], 1.5][mt_rand(0, 5)],
            'nan'      => static fn (): float|int => [\NAN, 1, 2][mt_rand(0, 2)],
        ];

        for ($case = 0; $case < 300; ++$case) {
            $width = mt_rand(0, 3);
            $kinds = array_map(static fn (): string => array_rand($pools), array_fill(0, $width, null));
            $keys  = [];
            for ($row = 0, $rows = mt_rand(0, 12); $row < $rows; ++$row) {
                $keys[] = array_map(static fn (string $kind): mixed => $pools[$kind](), $kinds);
            }

            $indices  = array_keys($keys);
            $expected = $indices;
            usort($expected, static fn (int $left, int $right): int => Values::compare($keys[$left], $keys[$right]));

            $context = json_encode([$kinds, $keys], \JSON_PARTIAL_OUTPUT_ON_ERROR);
            self::assertSame($expected, Harness::call('_sort_by_impl', $indices, [$keys]), $context);

            $groups = [];
            foreach ($expected as $position => $index) {
                if (0 === $position || 0 !== Values::compare($keys[$expected[$position - 1]], $keys[$index])) {
                    $groups[] = [];
                }

                $groups[array_key_last($groups)][] = $index;
            }

            self::assertSame($groups, Harness::call('_group_by_impl', $indices, [$keys]), $context);
        }
    }

    public function testSortListIsPublic(): void
    {
        self::assertSame([1, 2], CollectionFunctions::sortList([2, 1]));
        self::assertSame([1, 2], CollectionFunctions::sortList(['x' => 2, 'y' => 1]));
    }

    public function testPreciseNumbersSortByTheirValue(): void
    {
        $big    = new PreciseNumber(1.0e20, '100000000000000000000');
        $sorted = Harness::call('sort', [$big, 5, 1.5]);

        self::assertIsArray($sorted);
        self::assertSame([1.5, 5], \array_slice($sorted, 0, 2));
        self::assertSame($big, $sorted[2]);
    }
}
