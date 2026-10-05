<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Binary;
use LTS\PhpXq\Jq\Ast\BinaryOpEnum;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BinaryTest extends TestCase
{
    public function testCarriesOperatorAndOperands(): void
    {
        $left  = new Identity();
        $right = new Literal(1);
        $node  = new Binary(BinaryOpEnum::Add, $left, $right);

        self::assertSame(BinaryOpEnum::Add, $node->op);
        self::assertSame($left, $node->left);
        self::assertSame($right, $node->right);
    }
}
