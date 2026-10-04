<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\Eval\Assignment;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Assignment::class)]
final class AssignmentTest extends TestCase
{
    use AssertsRaised;

    public function testSetAllReplacesEveryPath(): void
    {
        $result = Assignment::setAll([1, 2, 3], static fn (mixed $old): mixed => Arithmetic::multiply($old, 10), [0], [2]);

        self::assertSame([10, 2, 30], $result);
    }

    public function testSetAllWithASinglePath(): void
    {
        self::assertEquals(
            new JsonObject(['a' => 2]),
            Assignment::setAll(new JsonObject(['a' => 1]), static fn (mixed $old): mixed => Arithmetic::add($old, 1), ['a']),
        );
    }

    public function testSetAllWithoutPathsReturnsTheInput(): void
    {
        self::assertSame([1], Assignment::setAll([1], static fn (): mixed => 9));
    }

    public function testBatchedAndSequentialApplicationAgree(): void
    {
        $input = new JsonObject(['a' => [1, 2], 'b' => new JsonObject(['c' => 3])]);
        $paths = [['a', 0], ['a', 1], ['b', 'c'], ['d']];

        $batched    = Assignment::setAll($input, static fn (mixed $old): mixed => [$old], ...$paths);
        $sequential = $input;
        foreach ($paths as $path) {
            $sequential = \LTS\PhpXq\Jq\Runtime\PathOps::setPath($sequential, [\LTS\PhpXq\Jq\Runtime\PathOps::getPath($sequential, ...$path)], ...$path);
        }

        self::assertEquals($sequential, $batched);
    }

    public function testBatchedWritesCreateMissingContainers(): void
    {
        $result = Assignment::setAll(null, static fn (mixed $old): mixed => 1, ['a', 'x'], ['a', 'y'], ['b', 2]);

        self::assertEquals(new JsonObject(['a' => new JsonObject(['x' => 1, 'y' => 1]), 'b' => [null, null, 1]]), $result);
    }

    public function testOverlappingPathsAreAppliedSequentially(): void
    {
        $result = Assignment::setAll(
            new JsonObject(['a' => new JsonObject(['b' => 1])]),
            static fn (mixed $old): mixed => $old instanceof JsonObject ? new JsonObject(['b' => 5]) : Arithmetic::add($old, 1),
            ['a'],
            ['a', 'b'],
        );

        self::assertEquals(new JsonObject(['a' => new JsonObject(['b' => 6])]), $result);
    }

    public function testBatchedStructureErrorsKeepJqsMessages(): void
    {
        self::assertRaises(JqException::class, 'Cannot index array with string ("a")', static fn (): mixed => Assignment::setAll([1], static fn (): mixed => 1, ['a'], ['b']));
    }

    public function testBatchedWriteRejectsHugeIndexes(): void
    {
        self::assertRaises(JqException::class, 'Array index too large', static fn (): mixed => Assignment::setAll([], static fn (): mixed => 1, [0], [536870912]));
    }

    public function testUpdateAllDeletesPathsWithoutAnOutput(): void
    {
        $result = Assignment::updateAll(
            [1, 5, 3, 0, 7],
            static fn (mixed $old): array => $old >= 2 ? [] : [$old],
            [1],
            [2],
            [4],
        );

        self::assertSame([1, 0], $result);
    }

    public function testUpdateAllSequentialPath(): void
    {
        $result = Assignment::updateAll([1, 2, 3], static fn (mixed $old): array => [Arithmetic::add($old, 1)], [0]);

        self::assertSame([2, 2, 3], $result);
        self::assertSame([2, 3], Assignment::updateAll([1, 2, 3], static fn (): array => [], [0]));
    }

    public function testDeletingEveryPathBelowANullDoesNotCreateContainers(): void
    {
        self::assertNull(Assignment::updateAll(null, static fn (): array => [], ['a', 'x'], ['a', 'y']));
    }

    public function testDeletionsMixWithUpdatesInOneBatch(): void
    {
        $result = Assignment::updateAll(
            new JsonObject(['a' => 1, 'b' => 2, 'c' => 3]),
            static fn (mixed $old): array => 2 === $old ? [] : [Arithmetic::multiply($old, 10)],
            ['a'],
            ['b'],
            ['c'],
        );

        self::assertEquals(new JsonObject(['a' => 10, 'c' => 30]), $result);
    }

    public function testCollectGathersThePathsOfAPathExpression(): void
    {
        $paths = Assignment::collect(new IterateOp(null), null, [7, 8]);

        self::assertSame([[0], [1]], $paths);
        self::assertSame([['a']], Assignment::collect(new FieldOp('a'), null, new JsonObject(['a' => 1])));
    }

    public function testCollectRejectsExpressionsThatAreNotPaths(): void
    {
        self::assertRaises(JqException::class, 'Invalid path expression with result 5', static fn (): mixed => Assignment::collect(new \LTS\PhpXq\Jq\Runtime\Eval\ConstOp(5), null, null));
    }

    public function testArithmeticOperationsCanDriveSetAll(): void
    {
        self::assertSame([3, 4], Assignment::setAll([1, 2], static fn (mixed $old): mixed => Arithmetic::add($old, 2), [0], [1]));
    }
}
