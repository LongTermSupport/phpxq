<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Variable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class VariableTest extends TestCase
{
    public function testNameHasNoDollar(): void
    {
        $variable = new Variable('ENV', 2);

        self::assertSame('ENV', $variable->name);
        self::assertSame(2, $variable->line);
    }
}
