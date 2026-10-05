<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\HaltException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HaltExceptionTest extends TestCase
{
    public function testCarriesExitCodeAndStderrText(): void
    {
        $halt = new HaltException(5, "bye\n");

        self::assertSame(5, $halt->exitCode);
        self::assertSame("bye\n", $halt->stderrText);
    }

    public function testMessageIsHalt(): void
    {
        self::assertSame('halt', new HaltException(1)->getMessage());
    }

    public function testPlainHaltHasNoStderrText(): void
    {
        self::assertNull(new HaltException(0)->stderrText);
    }
}
