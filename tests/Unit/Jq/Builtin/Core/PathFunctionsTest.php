<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\ClosureFilter;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PathFunctionsTest extends TestCase
{
    public function testPathListsThePathsAFilterReaches(): void
    {
        $input = Harness::json('{"a":[1,2]}');

        self::assertSame([['a', 0], ['a', 1]], Harness::stream('path', $input, [$this->throughA()]));
    }

    public function testPathOfIdentityIsTheEmptyPath(): void
    {
        self::assertSame([[]], Harness::stream('path', 42, [ClosureFilter::identity()]));
    }

    public function testPathRejectsComputedValues(): void
    {
        self::assertSame(
            'Invalid path expression with result [2,1,0]',
            Harness::streamError('path', [0, 1, 2], [ClosureFilter::of(static fn (mixed $v): mixed => [2, 1, 0])]),
        );
    }

    public function testPathInPathModeProducesComputedValues(): void
    {
        self::assertSame([[null, ['a']]], Harness::paths('path', [], Harness::json('{"a":1}'), [ClosureFilter::field('a')]));
    }

    public function testGetPath(): void
    {
        $input = Harness::json('{"a":{"b":0,"c":1}}');

        self::assertSame([0, 1], Harness::stream('getpath', $input, [ClosureFilter::constants(['a', 'b'], ['a', 'c'])]));
        self::assertSame([null], Harness::stream('getpath', null, [ClosureFilter::constants(['a', 'b'])]));
        self::assertSame([$input], Harness::stream('getpath', $input, [ClosureFilter::constants([])]));
    }

    public function testGetPathInPathMode(): void
    {
        $input = Harness::json('{"a":{"b":0}}');

        self::assertSame([[['x', 'a', 'b'], 0]], Harness::paths('getpath', ['x'], $input, [ClosureFilter::constants(['a', 'b'])]));
        self::assertSame([[null, 0]], Harness::paths('getpath', null, $input, [ClosureFilter::constants(['a', 'b'])]));
    }

    public function testGetPathRequiresAnArray(): void
    {
        self::assertSame('Path must be specified as an array', Harness::streamError('getpath', [], [ClosureFilter::constants('a')]));
    }

    public function testGetPathRejectsTooDeepPaths(): void
    {
        self::assertSame('Path too deep', Harness::streamError('getpath', null, [ClosureFilter::constants(array_fill(0, 10001, 0))]));
    }

    public function testSetPath(): void
    {
        self::assertEquals(Harness::json('{"a":{"b":1}}'), Harness::call('setpath', null, [['a', 'b'], 1]));
        self::assertEquals(Harness::json('{"a":{"b":1}}'), Harness::call('setpath', Harness::json('{"a":{"b":0}}'), [['a', 'b'], 1]));
        self::assertEquals(Harness::json('[{"a":1}]'), Harness::call('setpath', null, [[0, 'a'], 1]));
        self::assertSame(5, Harness::call('setpath', 1, [[], 5]));
    }

    public function testSetPathValidation(): void
    {
        self::assertSame('Path must be specified as an array', Harness::error('setpath', null, ['a', 1]));
        self::assertSame('Path too deep', Harness::error('setpath', null, [array_fill(0, 10001, 0), 0]));
        self::assertSame('Cannot index object with number (1)', Harness::error('setpath', Harness::json('{"hi":"hello"}'), [[1], 1]));
    }

    public function testDelPaths(): void
    {
        $input = Harness::json('{"bar":42,"foo":["a","b","c","d"]}');

        self::assertEquals(Harness::json('{"bar":42,"foo":["a","c","d"]}'), Harness::call('delpaths', $input, [[['foo', 1]]]));
        self::assertEquals(Harness::json('{"foo":["a","b","c","d"]}'), Harness::call('delpaths', $input, [[['bar']]]));
        self::assertNull(Harness::call('delpaths', 1, [[[]]]));
        self::assertEquals($input, Harness::call('delpaths', $input, [[]]));
    }

    public function testDelPathsValidation(): void
    {
        self::assertSame('Paths must be specified as an array', Harness::error('delpaths', [], [0]));
        self::assertSame('Path must be specified as an array', Harness::error('delpaths', [], [[0]]));
        self::assertSame('Path too deep', Harness::error('delpaths', [], [[array_fill(0, 10001, 0)]]));
    }

    public function testPathsListsEveryDescendantPath(): void
    {
        self::assertSame(
            [[0], [1], [1, 0], [1, 1], [1, 1, 'a']],
            Harness::stream('paths', Harness::json('[1,[[],{"a":2}]]')),
        );
        self::assertSame([], Harness::stream('paths', 3));
        self::assertSame([['b'], ['b', 0]], Harness::stream('paths', Harness::json('{"b":[1]}')));
    }

    public function testToStreamEmitsLeavesAndClosingEvents(): void
    {
        self::assertSame(
            [[[0], 1], [[1, 0], 2], [[1, 0]], [[1]]],
            Harness::stream('tostream', Harness::json('[1,[2]]')),
        );
        self::assertSame([[[], 3]], Harness::stream('tostream', 3));
        self::assertSame([[[], []]], Harness::stream('tostream', []));
        self::assertEquals([[[], Harness::json('{}')]], Harness::stream('tostream', Harness::json('{}')));
    }

    public function testToStreamOfObjects(): void
    {
        self::assertSame(
            [[['a'], 1], [['b', 'c'], []], [['b', 'c']], [['b']]],
            Harness::stream('tostream', Harness::json('{"a":1,"b":{"c":[]}}')),
        );
        self::assertSame(
            [[['1'], 'x'], [['1']]],
            Harness::stream('tostream', Harness::json('{"1":"x"}')),
        );
    }

    public function testFromStreamRebuildsValues(): void
    {
        $events = ClosureFilter::constants([[0], 'a'], [[1, 0], 'b'], [[1, 0]], [[1]]);

        self::assertSame([['a', ['b']]], Harness::stream('fromstream', null, [$events]));
    }

    public function testFromStreamEmitsEachTopLevelValue(): void
    {
        $events = ClosureFilter::constants([[], 1], [['a'], 2], [['a']], [[], 3]);

        self::assertEquals([1, Harness::json('{"a":2}'), 3], Harness::stream('fromstream', null, [$events]));
    }

    public function testFromStreamRoundTripsToStream(): void
    {
        $value  = Harness::json('[0,[1,{"a":1},{"b":2}],{"c":[]}]');
        $events = new ClosureFilter(static function (mixed $input, Closure $emit): void {
            foreach (Harness::stream('tostream', $input) as $event) {
                $emit($event);
            }
        });

        $rebuilt = Harness::stream('fromstream', $value, [$events]);

        self::assertCount(1, $rebuilt);
        self::assertEquals($value, $rebuilt[0]);
    }

    public function testFromStreamRejectsMalformedEvents(): void
    {
        self::assertSame('Invalid stream event', Harness::streamError('fromstream', null, [ClosureFilter::constants(5)]));
    }

    private function throughA(): ClosureFilter
    {
        return new ClosureFilter(
            static function (): void {
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                $emit([...(array)$path, 'a', 0], 1);
                $emit([...(array)$path, 'a', 1], 2);
            },
        );
    }
}
