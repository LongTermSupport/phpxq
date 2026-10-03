<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Pipe;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PipeTest extends TestCase
{
    public function testCarriesBothSides(): void
    {
        $left  = new Identity();
        $right = new Literal(1);
        $node  = new Pipe($left, $right);

        self::assertSame($left, $node->left);
        self::assertSame($right, $node->right);
    }
}
