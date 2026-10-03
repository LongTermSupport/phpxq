<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\ClosureFilter;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Collector;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\FakeContext;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @internal
 */
final class ControlFunctionsTest extends TestCase
{
    public function testEmptyProducesNothing(): void
    {
        self::assertSame([], Harness::stream('empty', 1));
        self::assertSame([], Harness::paths('empty', ['a'], 1));
    }

    public function testErrorThrowsTheInput(): void
    {
        self::assertSame('boom', Harness::streamError('error', 'boom'));
    }

    public function testErrorKeepsNonStringValues(): void
    {
        try {
            Harness::stream('error', new JsonObject(['a' => 1]));
            self::fail('no error raised');
        } catch (JqException $jqException) {
            self::assertEquals(new JsonObject(['a' => 1]), $jqException->value);
        }
    }

    public function testErrorWithMessageThrowsTheFirstMessage(): void
    {
        try {
            Harness::stream('error', 1, [ClosureFilter::constants('first', 'second')]);
            self::fail('no error raised');
        } catch (JqException $jqException) {
            self::assertSame('first', $jqException->value);
        }
    }

    public function testErrorNullIsCatchableAsNull(): void
    {
        try {
            Harness::stream('error', 1, [ClosureFilter::constants(null)]);
            self::fail('no error raised');
        } catch (JqException $jqException) {
            self::assertNull($jqException->value);
        }
    }

    public function testErrorInPathMode(): void
    {
        try {
            Harness::paths('error', ['a'], 'x');
            self::fail('no error raised');
        } catch (JqException $jqException) {
            self::assertSame('x', $jqException->value);
        }
    }

    public function testErrorWithMessageInPathMode(): void
    {
        try {
            Harness::paths('error', ['a'], 'x', [ClosureFilter::constants('m')]);
            self::fail('no error raised');
        } catch (JqException $jqException) {
            self::assertSame('m', $jqException->value);
        }
    }

    public function testSelectKeepsInputWhenTheConditionIsTruthy(): void
    {
        self::assertSame([5, 5], Harness::stream('select', 5, [ClosureFilter::constants(true, false, null, 0)]));
        self::assertSame([], Harness::stream('select', 5, [ClosureFilter::constants(false, null)]));
    }

    public function testSelectInPathMode(): void
    {
        self::assertSame([[['a'], 5]], Harness::paths('select', ['a'], 5, [ClosureFilter::constants(true, false)]));
        self::assertSame([[null, 5]], Harness::paths('select', null, 5, [ClosureFilter::constants(true)]));
    }

    public function testMapOverArraysAndObjects(): void
    {
        $double = ClosureFilter::of(static fn (mixed $value): mixed => \is_int($value) ? $value * 2 : $value);

        self::assertSame([[2, 4, 6]], Harness::stream('map', [1, 2, 3], [$double]));
        self::assertSame([[2, 4]], Harness::stream('map', new JsonObject(['a' => 1, 'b' => 2]), [$double]));
    }

    public function testMapCollectsEveryOutput(): void
    {
        $twice = new ClosureFilter(static function (mixed $input, Closure $emit): void {
            $emit($input);
            $emit($input);
        });

        self::assertSame([[1, 1, 2, 2]], Harness::stream('map', [1, 2], [$twice]));
        self::assertSame([[]], Harness::stream('map', [], [$twice]));
    }

    public function testMapRejectsScalars(): void
    {
        self::assertSame('Cannot iterate over number (5)', Harness::streamError('map', 5, [ClosureFilter::identity()]));
    }

    public function testMapRejectsNull(): void
    {
        self::assertSame('Cannot iterate over null (null)', Harness::streamError('map', null, [ClosureFilter::identity()]));
    }

    public function testFirstStopsAtTheFirstOutput(): void
    {
        $generator = new ClosureFilter(static function (mixed $input, Closure $emit): never {
            $emit(1);

            throw new RuntimeException('evaluated beyond the first output');
        });

        self::assertSame([1], Harness::stream('first', null, [$generator]));
        self::assertSame([], Harness::stream('first', null, [ClosureFilter::constants()]));
    }

    public function testFirstInPathMode(): void
    {
        self::assertSame([[['a', 0], 'x']], Harness::paths('first', ['a'], [1, 2], [new ClosureFilter(
            static function (mixed $input, Closure $emit): void {
                $emit('x');
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                $emit([...(array)$path, 0], 'x');
                $emit([...(array)$path, 1], 'y');
            },
        )]));
    }

    public function testFirstLetsAForeignBreakThrough(): void
    {
        $foreign = new stdClass();
        $builtin = Harness::registry()->lookup('first', 1);
        self::assertInstanceOf(StreamBuiltinInterface::class, $builtin);

        try {
            $builtin->run(new FakeContext(), null, [ClosureFilter::constants(1)], static function () use ($foreign): never {
                throw new BreakException($foreign);
            });
            self::fail('the foreign break was swallowed');
        } catch (BreakException $breakException) {
            self::assertSame($foreign, $breakException->label);
        }
    }

    public function testLimit(): void
    {
        $source = ClosureFilter::constants(1, 2, 3, 4);

        self::assertSame([1, 2], Harness::stream('limit', null, [ClosureFilter::constants(2), $source]));
        self::assertSame([1, 2, 3, 4], Harness::stream('limit', null, [ClosureFilter::constants(10), $source]));
        self::assertSame([], Harness::stream('limit', null, [ClosureFilter::constants(0), $source]));
        self::assertSame([1, 1, 2], Harness::stream('limit', null, [ClosureFilter::constants(1, 2), $source]));
    }

    public function testLimitDoesNotEvaluateBeyondTheLastOutput(): void
    {
        $generator = new ClosureFilter(static function (mixed $input, Closure $emit): never {
            $emit(1);

            throw new RuntimeException('evaluated beyond the limit');
        });

        self::assertSame([1], Harness::stream('limit', null, [ClosureFilter::constants(1), $generator]));
    }

    public function testLimitZeroDoesNotRunTheGenerator(): void
    {
        $generator = new ClosureFilter(static function (): never {
            throw new RuntimeException('the generator must not run');
        });

        self::assertSame([], Harness::stream('limit', null, [ClosureFilter::constants(0), $generator]));
    }

    public function testLimitRejectsNegativeCounts(): void
    {
        self::assertSame(
            "limit doesn't support negative count",
            Harness::streamError('limit', null, [ClosureFilter::constants(-1), ClosureFilter::constants(1)]),
        );
    }

    public function testLimitRejectsNonNumbers(): void
    {
        self::assertSame(
            "limit doesn't support negative count",
            Harness::streamError('limit', null, [ClosureFilter::constants('a'), ClosureFilter::constants(1)]),
        );
    }

    public function testLimitInPathMode(): void
    {
        $paths = new ClosureFilter(
            static function (): void {
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                $emit([...(array)$path, 0], 'a');
                $emit([...(array)$path, 1], 'b');
                $emit([...(array)$path, 2], 'c');
            },
        );

        self::assertSame([[[0], 'a'], [[1], 'b']], Harness::paths('limit', [], null, [ClosureFilter::constants(2), $paths]));
        self::assertSame([], Harness::paths('limit', [], null, [ClosureFilter::constants(0), $paths]));
    }

    public function testSkip(): void
    {
        $source = ClosureFilter::constants(1, 2, 3, 4);

        self::assertSame([3, 4], Harness::stream('skip', null, [ClosureFilter::constants(2), $source]));
        self::assertSame([1, 2, 3, 4], Harness::stream('skip', null, [ClosureFilter::constants(0), $source]));
        self::assertSame([], Harness::stream('skip', null, [ClosureFilter::constants(9), $source]));
    }

    public function testSkipWithSeveralCounts(): void
    {
        self::assertSame([1, 2, 3, 4, 4, 3, 4], Harness::stream('skip', null, [ClosureFilter::constants(0, 3, 2), ClosureFilter::constants(1, 2, 3, 4)]));
    }

    public function testSkipRejectsNegativeCounts(): void
    {
        self::assertSame(
            "skip doesn't support negative count",
            Harness::streamError('skip', null, [ClosureFilter::constants(-1), ClosureFilter::constants(1)]),
        );
    }

    public function testSkipInPathMode(): void
    {
        $paths = new ClosureFilter(
            static function (): void {
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                $emit([0], 'a');
                $emit([1], 'b');
            },
        );

        self::assertSame([[[1], 'b']], Harness::paths('skip', [], null, [ClosureFilter::constants(1), $paths]));
    }

    public function testLast(): void
    {
        self::assertSame([3], Harness::stream('last', null, [ClosureFilter::constants(1, 2, 3)]));
        self::assertSame([], Harness::stream('last', null, [ClosureFilter::constants()]));
    }

    public function testIsEmpty(): void
    {
        self::assertSame([true], Harness::stream('isempty', null, [ClosureFilter::constants()]));
        self::assertSame([false], Harness::stream('isempty', null, [ClosureFilter::constants(1, 2)]));
        $failing = new ClosureFilter(static function (mixed $input, Closure $emit): never {
            $emit(1);

            throw new RuntimeException('evaluated beyond the first output');
        });
        self::assertSame([false], Harness::stream('isempty', null, [$failing]));
    }

    public function testAny(): void
    {
        self::assertSame([true], Harness::stream('any', [1, 2], [ClosureFilter::iterate(), ClosureFilter::identity()]));
        self::assertSame([false], Harness::stream('any', [false, null], [ClosureFilter::iterate(), ClosureFilter::identity()]));
        self::assertSame([false], Harness::stream('any', [], [ClosureFilter::iterate(), ClosureFilter::identity()]));
    }

    public function testAnyShortCircuits(): void
    {
        $generator = new ClosureFilter(static function (mixed $input, Closure $emit): never {
            $emit(true);

            throw new RuntimeException('evaluated beyond the deciding output');
        });

        self::assertSame([true], Harness::stream('any', null, [$generator, ClosureFilter::identity()]));
    }

    public function testAll(): void
    {
        self::assertSame([true], Harness::stream('all', [1, true], [ClosureFilter::iterate(), ClosureFilter::identity()]));
        self::assertSame([false], Harness::stream('all', [1, false, 2], [ClosureFilter::iterate(), ClosureFilter::identity()]));
        self::assertSame([true], Harness::stream('all', [], [ClosureFilter::iterate(), ClosureFilter::identity()]));
    }

    public function testAllShortCircuits(): void
    {
        $generator = new ClosureFilter(static function (mixed $input, Closure $emit): never {
            $emit(false);

            throw new RuntimeException('evaluated beyond the deciding output');
        });

        self::assertSame([false], Harness::stream('all', null, [$generator, ClosureFilter::identity()]));
    }

    public function testRangeWithOneBound(): void
    {
        self::assertSame([0, 1, 2], Harness::stream('range', null, [ClosureFilter::constants(3)]));
        self::assertSame([0, 1, 2, 0, 1, 2, 3, 4], Harness::stream('range', null, [ClosureFilter::constants(3, 5)]));
        self::assertSame([], Harness::stream('range', null, [ClosureFilter::constants(0)]));
        self::assertSame([], Harness::stream('range', null, [ClosureFilter::constants(-2)]));
    }

    public function testRangeWithTwoBounds(): void
    {
        self::assertSame([2, 3], Harness::stream('range', null, [ClosureFilter::constants(2), ClosureFilter::constants(4)]));
        self::assertSame(
            [0, 1, 2, 0, 1, 2, 3, 1, 2, 1, 2, 3],
            Harness::stream('range', null, [ClosureFilter::constants(0, 1), ClosureFilter::constants(3, 4)]),
        );
        self::assertSame([0.5, 1.5, 2.5], Harness::stream('range', null, [ClosureFilter::constants(0.5), ClosureFilter::constants(3)]));
        self::assertSame([0, 1, 2], Harness::stream('range', null, [ClosureFilter::constants(0.0), ClosureFilter::constants(2.5)]));
    }

    public function testRangeWithThreeBounds(): void
    {
        self::assertSame([0, 3, 6, 9], Harness::stream('range', null, [ClosureFilter::constants(0), ClosureFilter::constants(10), ClosureFilter::constants(3)]));
        self::assertSame([], Harness::stream('range', null, [ClosureFilter::constants(0), ClosureFilter::constants(10), ClosureFilter::constants(-1)]));
        self::assertSame([0, -1, -2, -3, -4], Harness::stream('range', null, [ClosureFilter::constants(0), ClosureFilter::constants(-5), ClosureFilter::constants(-1)]));
        self::assertSame([], Harness::stream('range', null, [ClosureFilter::constants(0), ClosureFilter::constants(10), ClosureFilter::constants(0)]));
        self::assertSame([0, 0.5, 1, 1.5], Harness::stream('range', null, [ClosureFilter::constants(0), ClosureFilter::constants(2), ClosureFilter::constants(0.5)]));
    }

    public function testRangeOrdersItsLoopsFromFirstOuterToLastInner(): void
    {
        self::assertSame(
            [0, 2, 0, 3, 0, 2, 0, 0, 0, 1, 3, 1, 1, 1, 1, 1, 2, 2, 2, 2],
            Harness::stream('range', null, [ClosureFilter::constants(0, 1, 2), ClosureFilter::constants(4, 3, 2), ClosureFilter::constants(2, 3)]),
        );
    }

    public function testRangeRejectsNonNumbers(): void
    {
        self::assertSame(
            'Range bounds must be numeric',
            Harness::streamError('range', null, [ClosureFilter::constants('a'), ClosureFilter::constants(3)]),
        );
    }

    public function testRangeRejectsANonNumericStep(): void
    {
        self::assertSame(
            'Range bounds must be numeric',
            Harness::streamError('range', null, [ClosureFilter::constants(0), ClosureFilter::constants(3), ClosureFilter::constants('a')]),
        );
    }

    public function testRecurseWithoutArguments(): void
    {
        $input = new JsonObject(['a' => 0, 'b' => [1]]);

        self::assertEquals([$input, 0, [1], 1], Harness::stream('recurse', $input));
        self::assertSame([5], Harness::stream('recurse', 5));
        self::assertSame([[], 1], \array_slice(Harness::stream('recurse', [[], 1]), 1));
    }

    public function testRecurseWithoutArgumentsInPathMode(): void
    {
        $input = new JsonObject(['a' => [1, 2]]);

        self::assertEquals(
            [[[], $input], [['a'], [1, 2]], [['a', 0], 1], [['a', 1], 2]],
            Harness::paths('recurse', [], $input),
        );
        self::assertSame([[null, [1]], [null, 1]], Harness::paths('recurse', null, [1]));
    }

    public function testRecurseWithAStep(): void
    {
        $children = new ClosureFilter(static function (mixed $input, Closure $emit): void {
            if (\is_int($input) && $input < 3) {
                $emit($input + 1);
            }
        });

        self::assertSame([0, 1, 2, 3], Harness::stream('recurse', 0, [$children]));
    }

    public function testRecurseWithAStepInPathMode(): void
    {
        $children = new ClosureFilter(
            static function (): void {
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                if (\is_int($input) && $input < 2) {
                    $emit(null === $path ? null : [...$path, 'next'], $input + 1);
                }
            },
        );

        self::assertSame([[[], 0], [['next'], 1], [['next', 'next'], 2]], Harness::paths('recurse', [], 0, [$children]));
    }

    public function testRecurseWithAStepAndACondition(): void
    {
        $square = ClosureFilter::of(static fn (mixed $v): mixed => \is_int($v) ? $v * $v : $v);
        $below  = ClosureFilter::of(static fn (mixed $v): mixed => \is_int($v) && $v < 20);

        self::assertSame([2, 4, 16], Harness::stream('recurse', 2, [$square, $below]));
    }

    public function testRecurseWithAStepAndAConditionInPathMode(): void
    {
        $step = new ClosureFilter(
            static function (): void {
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                if (\is_int($input)) {
                    $emit(null === $path ? null : [...$path, 'n'], $input + 1);
                }
            },
        );
        $below = ClosureFilter::of(static fn (mixed $v): mixed => \is_int($v) && $v < 2);

        self::assertSame([[[], 0], [['n'], 1]], Harness::paths('recurse', [], 0, [$step, $below]));
    }

    public function testRepeatApplesTheFilterToTheSameInputAgainAndAgain(): void
    {
        $builtin = Harness::registry()->lookup('repeat', 1);
        self::assertInstanceOf(StreamBuiltinInterface::class, $builtin);
        $label = new stdClass();
        $out   = new Collector();

        try {
            $builtin->run(new FakeContext(), 1, [ClosureFilter::constants(2, 7)], static function (mixed $value) use ($out, $label): void {
                $out->collect($value);
                if ($out->count() >= 5) {
                    throw new BreakException($label);
                }
            });
            self::fail('repeat ended by itself');
        } catch (BreakException $breakException) {
            self::assertSame($label, $breakException->label);
        }

        self::assertSame([2, 7, 2, 7, 2], $out->items);
    }

    public function testRepeatEndsWithAnError(): void
    {
        $filter = new ClosureFilter(static function (mixed $input, Closure $emit): never {
            $emit(2);

            throw new JqException($input);
        });
        $builtin = Harness::registry()->lookup('repeat', 1);
        self::assertInstanceOf(StreamBuiltinInterface::class, $builtin);
        $out = new Collector();

        try {
            $builtin->run(new FakeContext(), 1, [$filter], $out->collect(...));
            self::fail('repeat ended without an error');
        } catch (JqException $jqException) {
            self::assertSame(1, $jqException->value);
        }

        self::assertSame([2], $out->items);
    }
}
