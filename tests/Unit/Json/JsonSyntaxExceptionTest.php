<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\JsonSyntaxException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonSyntaxExceptionTest extends TestCase
{
    public function testKeepsJqPhrasedMessage(): void
    {
        $exception = new JsonSyntaxException('Unfinished JSON term at EOF at line 2, column 0');

        self::assertSame('Unfinished JSON term at EOF at line 2, column 0', $exception->getMessage());
    }
}
