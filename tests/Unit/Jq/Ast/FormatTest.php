<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Format;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatTest extends TestCase
{
    public function testNameHasNoAtSign(): void
    {
        self::assertSame('csv', new Format('csv')->name);
    }
}
