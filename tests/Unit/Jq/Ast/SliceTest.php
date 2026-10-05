<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Slice;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SliceTest extends TestCase
{
    public function testBoundsMayBeOmitted(): void
    {
        $from = new Literal(1);
        $node = new Slice(new Identity(), $from, null);

        self::assertSame($from, $node->from);
        self::assertNull($node->to);
    }
}
