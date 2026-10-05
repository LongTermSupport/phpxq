<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use Throwable;

/**
 * Assert that an operation raises an exception of a class with an exact message.
 */
trait AssertsRaised
{
    /**
     * @param class-string<Throwable> $class
     * @param Closure(): mixed        $operation
     */
    protected static function assertRaises(string $class, string $message, Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            self::assertTrue($throwable instanceof $class, 'Unexpected ' . $throwable::class . ': ' . $throwable->getMessage());
            self::assertSame($message, $throwable->getMessage());

            return;
        }

        self::fail('Expected ' . $class . ' with message: ' . $message);
    }
}
