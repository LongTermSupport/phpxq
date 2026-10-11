<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\JqExitCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class JqExitCodeTest extends TestCase
{
    public function testMatchesTheDocumentedJqCodes(): void
    {
        $constants = new ReflectionClass(JqExitCode::class)->getConstants();

        self::assertSame(0, $constants['OK']);
        self::assertSame(1, $constants['LAST_OUTPUT_FALSY']);
        self::assertSame(2, $constants['USAGE']);
        self::assertSame(3, $constants['COMPILE_ERROR']);
        self::assertSame(4, $constants['NO_OUTPUT']);
    }
}
