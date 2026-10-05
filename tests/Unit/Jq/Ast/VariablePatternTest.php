<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\VariablePattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class VariablePatternTest extends TestCase
{
    public function testNamesAVariableWithoutDollar(): void
    {
        self::assertSame('x', new VariablePattern('x')->name);
    }
}
