<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Bind;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BindTest extends TestCase
{
    public function testHoldsDestructuringAlternatives(): void
    {
        $patterns = [new VariablePattern('a'), new VariablePattern('b')];
        $node     = new Bind(new Identity(), $patterns, new Identity());

        self::assertSame($patterns, $node->patterns);
    }
}
