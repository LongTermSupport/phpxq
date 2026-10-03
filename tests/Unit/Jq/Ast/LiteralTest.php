<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Literal;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LiteralTest extends TestCase
{
    public function testHoldsConstantIncludingNull(): void
    {
        self::assertNull(new Literal(null)->value);
        self::assertSame('x', new Literal('x')->value);
    }
}
