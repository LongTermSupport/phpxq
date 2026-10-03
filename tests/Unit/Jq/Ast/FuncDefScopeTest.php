<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\FuncDefScope;
use LTS\PhpXq\Jq\Ast\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FuncDefScopeTest extends TestCase
{
    public function testBindsDefinitionOverRest(): void
    {
        $def  = new FuncDef('f', [], new Identity());
        $rest = new Identity();
        $node = new FuncDefScope($def, $rest);

        self::assertSame($def, $node->def);
        self::assertSame($rest, $node->rest);
    }
}
