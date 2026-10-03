<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Negate;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NegateTest extends TestCase
{
    public function testCarriesOperand(): void
    {
        $operand = new Identity();

        self::assertSame($operand, new Negate($operand)->operand);
    }
}
