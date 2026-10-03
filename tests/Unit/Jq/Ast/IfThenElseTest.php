<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\IfThenElse;
use LTS\PhpXq\Jq\Ast\Literal;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class IfThenElseTest extends TestCase
{
    public function testElseMayBeAbsent(): void
    {
        $condition = new Literal(true);
        $then      = new Literal(1);
        $node      = new IfThenElse($condition, $then, null);

        self::assertSame($condition, $node->condition);
        self::assertSame($then, $node->then);
        self::assertNull($node->else);
    }
}
