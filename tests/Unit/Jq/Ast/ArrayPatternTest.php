<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ArrayPattern;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ArrayPatternTest extends TestCase
{
    public function testKeepsElementOrder(): void
    {
        $elements = [new VariablePattern('a'), new VariablePattern('b')];

        self::assertSame($elements, new ArrayPattern($elements)->elements);
    }
}
