<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\UsageException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UsageExceptionTest extends TestCase
{
    public function testCarriesTheMessageAndDefaultsToTheHint(): void
    {
        $exception = new UsageException('jq: nope');

        self::assertSame('jq: nope', $exception->getMessage());
        self::assertFalse($exception->showShortUsage);
        self::assertTrue(new UsageException('x', true)->showShortUsage);
    }
}
