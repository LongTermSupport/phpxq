<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonObjectToArrayTest extends TestCase
{
    public function testToArrayReturnsStorageInInsertionOrder(): void
    {
        $object = JsonObject::fromPairs(['b' => 1, 'a' => [2], '1' => 3]);

        self::assertSame(['b' => 1, 'a' => [2], 1 => 3], $object->toArray());
    }

    public function testToArrayOfEmptyObject(): void
    {
        self::assertSame([], new JsonObject()->toArray());
    }
}
