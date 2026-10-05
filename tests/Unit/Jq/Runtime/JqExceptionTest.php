<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqExceptionTest extends TestCase
{
    public function testMessageErrorCarriesTheStringAsValue(): void
    {
        $exception = JqException::fromMessage('boom');

        self::assertSame('boom', $exception->value);
        self::assertSame('boom', $exception->getMessage());
    }

    public function testNonStringValueIsCarriedUnchanged(): void
    {
        $value     = JsonObject::fromPairs(['a' => 1]);
        $exception = new JqException($value);

        self::assertSame($value, $exception->value);
        self::assertSame('jq error (not a string)', $exception->getMessage());
    }

    public function testNullValueIsRepresentable(): void
    {
        self::assertNull(new JqException(null)->value);
    }
}
