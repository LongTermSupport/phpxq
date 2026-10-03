<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
final class ValuesTest extends TestCase
{
    #[DataProvider('provideTypeNames')]
    public function testTypeName(mixed $value, string $expected): void
    {
        self::assertSame($expected, Values::typeName($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideTypeNames(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'bool' => [false, 'boolean'];
        yield 'int' => [1, 'number'];
        yield 'float' => [1.5, 'number'];
        yield 'precise' => [new PreciseNumber(1.0, '1.0'), 'number'];
        yield 'string' => ['a', 'string'];
        yield 'array' => [[], 'array'];
        yield 'object' => [new JsonObject(), 'object'];
    }

    public function testTypeNameRejectsForeignValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Values::typeName(new stdClass());
    }

    public function testOnlyNullAndFalseAreFalsy(): void
    {
        self::assertFalse(Values::isTruthy(null));
        self::assertFalse(Values::isTruthy(false));
        self::assertTrue(Values::isTruthy(0));
        self::assertTrue(Values::isTruthy(''));
        self::assertTrue(Values::isTruthy([]));
    }

    public function testCrossTypeOrdering(): void
    {
        $ordered = [null, false, true, 0, 'a', [], new JsonObject()];
        for ($i = 1, $count = \count($ordered); $i < $count; ++$i) {
            self::assertSame(-1, Values::compare($ordered[$i - 1], $ordered[$i]));
            self::assertSame(1, Values::compare($ordered[$i], $ordered[$i - 1]));
        }
    }

    public function testNumbersCompareByValueAcrossRepresentations(): void
    {
        self::assertTrue(Values::equals(1, 1.0));
        self::assertTrue(Values::equals(1, new PreciseNumber(1.0, '1.000')));
        self::assertSame(-1, Values::compare(NAN, -INF));
        self::assertSame(1, Values::compare(0, NAN));
    }

    public function testStringsCompareByCodepoint(): void
    {
        self::assertSame(-1, Values::compare('Z', 'a'));
        self::assertSame(-1, Values::compare('a', 'é'));
    }

    public function testArraysCompareLexicographically(): void
    {
        self::assertSame(-1, Values::compare([1, 2], [1, 3]));
        self::assertSame(-1, Values::compare([1], [1, 0]));
        self::assertSame(0, Values::compare([1, [2]], [1, [2]]));
    }

    public function testObjectsCompareKeySetsFirstThenValues(): void
    {
        $a1 = JsonObject::fromPairs(['a' => 1]);
        $b0 = JsonObject::fromPairs(['b' => 0]);
        $a2 = JsonObject::fromPairs(['a' => 2]);

        self::assertSame(-1, Values::compare($a1, $b0));
        self::assertSame(-1, Values::compare($a1, $a2));
        self::assertSame(0, Values::compare(JsonObject::fromPairs(['x' => 1, 'y' => 2]), JsonObject::fromPairs(['y' => 2, 'x' => 1])));
    }
}
