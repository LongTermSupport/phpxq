<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ObjectOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ObjectOp::class)]
final class ObjectOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testLastEntryVariesFastest(): void
    {
        $op = new ObjectOp([
            [self::constant('a'), self::generator(1, 2)],
            [self::constant('b'), self::generator(3, 4)],
        ]);

        self::assertEquals(
            [
                self::object(['a' => 1, 'b' => 3]),
                self::object(['a' => 1, 'b' => 4]),
                self::object(['a' => 2, 'b' => 3]),
                self::object(['a' => 2, 'b' => 4]),
            ],
            self::outputs($op),
        );
    }

    public function testKeyIsTheOuterLoopOfAnEntry(): void
    {
        $op = new ObjectOp([[self::generator('a', 'b'), self::generator(1, 2)]]);

        self::assertEquals(
            [self::object(['a' => 1]), self::object(['a' => 2]), self::object(['b' => 1]), self::object(['b' => 2])],
            self::outputs($op),
        );
    }

    public function testSingleEntriesAroundAGenerator(): void
    {
        $op = new ObjectOp([
            [self::constant('x'), self::constant(0)],
            [self::constant('y'), self::generator(1, 2)],
            [self::constant('z'), self::constant(9)],
        ]);

        self::assertEquals(
            [self::object(['x' => 0, 'y' => 1, 'z' => 9]), self::object(['x' => 0, 'y' => 2, 'z' => 9])],
            self::outputs($op),
        );
    }

    public function testAnEmptyValueYieldsNoObject(): void
    {
        self::assertSame([], self::outputs(new ObjectOp([[self::constant('a'), self::generator()]])));
    }

    public function testDuplicateKeysKeepTheFirstPositionAndTheLastValue(): void
    {
        $op = new ObjectOp([
            [self::constant('a'), self::constant(1)],
            [self::constant('b'), self::generator(2)],
            [self::constant('a'), self::constant(3)],
        ]);

        $result = self::outputs($op)[0];

        self::assertEquals(self::object(['a' => 3, 'b' => 2]), $result);
        self::assertInstanceOf(JsonObject::class, $result);
        self::assertSame(['a', 'b'], $result->keys());
    }

    public function testRejectsNonStringKeys(): void
    {
        self::assertRaises(JqException::class, 'Object keys must be strings', static fn (): mixed => self::outputs(new ObjectOp([[self::generator(1), self::constant(1)]])));
    }

    public function testRejectsNonStringKeysInSingleEntries(): void
    {
        $this->expectException(JqException::class);

        self::outputs(new ObjectOp([[self::constant(1), self::constant(1)], [self::constant('b'), self::generator(1)]]));
    }
}
