<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Reduce;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ReduceTest extends TestCase
{
    public function testCarriesAllParts(): void
    {
        $source  = new Identity();
        $pattern = new VariablePattern('x');
        $init    = new Literal(0);
        $update  = new Identity();
        $node    = new Reduce($source, $pattern, $init, $update);

        self::assertSame($source, $node->source);
        self::assertSame($pattern, $node->pattern);
        self::assertSame($init, $node->init);
        self::assertSame($update, $node->update);
    }
}
