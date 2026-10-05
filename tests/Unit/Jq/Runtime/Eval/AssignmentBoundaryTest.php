<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Assignment;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the batched assignment writer: missing containers, empty path sets and keyed path lists.
 *
 * @internal
 */
#[CoversClass(Assignment::class)]
final class AssignmentBoundaryTest extends TestCase
{
    public function testBatchedWritesBelowAMissingArrayElementCreateIt(): void
    {
        $result = Assignment::setAll([], static fn (): mixed => 5, [1, 'a'], [1, 'b']);

        self::assertEquals([null, new JsonObject(['a' => 5, 'b' => 5])], $result);
    }

    public function testBatchedWritesPadTheArrayUpToTheHighestIndex(): void
    {
        $result = Assignment::updateAll([1], static fn (): array => [7], [3, 'x'], [2, 'y']);

        self::assertEquals([1, null, new JsonObject(['y' => 7]), new JsonObject(['x' => 7])], $result);
    }

    public function testUpdateWithoutPathsReturnsTheInput(): void
    {
        self::assertSame([1], Assignment::updateAll([1], static fn (): array => [9]));
        self::assertSame(3, Assignment::updateAll(3, static fn (): array => []));
    }

    public function testKeyedPathListsAreTreatedAsLists(): void
    {
        $input = new JsonObject(['a' => 1, 'b' => 2]);

        $set = Assignment::setAll($input, static fn (mixed $old): mixed => [$old], ...['first' => ['a'], 'second' => ['b']]);
        self::assertEquals(new JsonObject(['a' => [1], 'b' => [2]]), $set);

        $updated = Assignment::updateAll($input, static fn (mixed $old): array => [[$old]], ...['first' => ['a'], 'second' => ['b']]);
        self::assertEquals(new JsonObject(['a' => [1], 'b' => [2]]), $updated);
    }

    public function testSequentialUpdateKeepsProcessingAfterADeletion(): void
    {
        $input = new JsonObject(['a' => new JsonObject(['b' => 1]), 'c' => 2]);

        // `.a.b`, `.a` and `.c` overlap, so they are applied one after the other; the first yields no output
        $result = Assignment::updateAll(
            $input,
            static fn (mixed $old): array => $old instanceof JsonObject || 2 === $old ? [new JsonObject(['k' => $old instanceof JsonObject ? 1 : 3])] : [],
            ['a', 'b'],
            ['a'],
            ['c'],
        );

        self::assertEquals(new JsonObject(['a' => new JsonObject(['k' => 1]), 'c' => new JsonObject(['k' => 3])]), $result);
    }
}
