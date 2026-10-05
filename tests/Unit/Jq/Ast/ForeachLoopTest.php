<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ForeachLoop;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ForeachLoopTest extends TestCase
{
    public function testExtractMayBeAbsent(): void
    {
        $node = new ForeachLoop(new Identity(), new VariablePattern('x'), new Literal(0), new Identity(), null);

        self::assertNull($node->extract);
        self::assertInstanceOf(VariablePattern::class, $node->pattern);
    }
}
