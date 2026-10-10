<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\EmptyInputs;
use LTS\PhpXq\Jq\Runtime\JqException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EmptyInputsTest extends TestCase
{
    public function testThereIsNothingToRead(): void
    {
        self::assertFalse(new EmptyInputs()->hasNext());
    }

    public function testReadingFailsLikeJq(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessageMatches('/^No more inputs$/');

        new EmptyInputs()->next();
    }
}
