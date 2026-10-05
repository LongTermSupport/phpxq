<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Assign;
use LTS\PhpXq\Jq\Ast\AssignOpEnum;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AssignTest extends TestCase
{
    public function testCarriesOperatorAndOperands(): void
    {
        $left  = new Identity();
        $right = new Literal(1);
        $node  = new Assign(AssignOpEnum::Set, $left, $right);

        self::assertSame(AssignOpEnum::Set, $node->op);
        self::assertSame($left, $node->left);
        self::assertSame($right, $node->right);
    }
}
