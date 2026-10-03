<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Index;
use LTS\PhpXq\Jq\Ast\Literal;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class IndexTest extends TestCase
{
    public function testCarriesTargetAndIndex(): void
    {
        $target = new Identity();
        $key    = new Literal('a');
        $node   = new Index($target, $key);

        self::assertSame($target, $node->target);
        self::assertSame($key, $node->index);
    }
}
