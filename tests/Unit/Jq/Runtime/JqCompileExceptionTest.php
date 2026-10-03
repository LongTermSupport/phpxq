<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\JqCompileException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqCompileExceptionTest extends TestCase
{
    public function testKeepsJqWording(): void
    {
        $exception = new JqCompileException('foo/0 is not defined at <top-level>, line 1:');

        self::assertSame('foo/0 is not defined at <top-level>, line 1:', $exception->getMessage());
    }
}
