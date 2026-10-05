<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\Nesting;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NestingTest extends TestCase
{
    public function testScalarsAndShallowValuesAreNotTooDeep(): void
    {
        self::assertFalse(Nesting::tooDeep(1));
        self::assertFalse(Nesting::tooDeep('x'));
        self::assertFalse(Nesting::tooDeep(null));
        self::assertFalse(Nesting::tooDeep([]));
        self::assertFalse(Nesting::tooDeep([[1], new JsonObject(['a' => [2]])]));
    }

    public function testTheLimitIsTenThousandWrappers(): void
    {
        self::assertFalse(Nesting::tooDeep($this->wrapped(10000)));
        self::assertTrue(Nesting::tooDeep($this->wrapped(10001)));
    }

    public function testObjectsCountToo(): void
    {
        $value = [];
        for ($i = 0; $i < 10001; ++$i) {
            $value = new JsonObject(['a' => $value]);
        }

        self::assertTrue(Nesting::tooDeep($value));
    }

    public function testDeepValuesInsideSiblingsAreFound(): void
    {
        self::assertTrue(Nesting::tooDeep([1, 'x', $this->wrapped(10001)]));
        self::assertTrue(Nesting::tooDeep(new JsonObject(['a' => 1, 'b' => $this->wrapped(10001)])));
    }

    /**
     * @return list<mixed>
     */
    private function wrapped(int $wrappers): array
    {
        $value = [];
        for ($i = 0; $i < $wrappers; ++$i) {
            $value = [$value];
        }

        return $value;
    }
}
