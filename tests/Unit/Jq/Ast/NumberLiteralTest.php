<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\NumberLiteral;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NumberLiteralTest extends TestCase
{
    public function testKeepsSourceText(): void
    {
        self::assertSame('13911860366432393', new NumberLiteral('13911860366432393')->text);
    }
}
