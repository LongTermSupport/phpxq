<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Comma;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CommaTest extends TestCase
{
    public function testCarriesBothSides(): void
    {
        $left  = new Identity();
        $right = new Literal(1);
        $node  = new Comma($left, $right);

        self::assertSame($left, $node->left);
        self::assertSame($right, $node->right);
    }
}
