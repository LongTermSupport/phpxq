<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonObjectTest extends TestCase
{
    public function testNumericStringKeysStayStringsAndKeepInsertionOrder(): void
    {
        $object = JsonObject::fromPairs(['b' => 1, '10' => 2, 'a' => 3, '2' => 4]);

        self::assertSame(['b', '10', 'a', '2'], $object->keys());
        self::assertSame(['10', '2', 'a', 'b'], $object->sortedKeys());
        self::assertSame([1, 2, 3, 4], $object->values());
        self::assertTrue($object->has('10'));
        self::assertSame(2, $object->get('10'));
    }

    public function testEntriesYieldStringKeys(): void
    {
        $keys = [];
        foreach (JsonObject::fromPairs(['1' => 'x'])->entries() as $key => $value) {
            self::assertSame('x', $value);
            $keys[] = $key;
        }

        self::assertSame(['1'], $keys);
    }

    public function testWithAndWithoutDoNotMutate(): void
    {
        $original = new JsonObject();
        $changed  = $original->with('k', null);

        self::assertCount(0, $original);
        self::assertCount(1, $changed);
        self::assertTrue($changed->has('k'));
        self::assertNull($changed->get('k'));
        self::assertNull($changed->get('missing'));
        self::assertCount(0, $changed->without('k'));
    }
}
