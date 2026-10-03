<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\TryCatch;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TryCatchTest extends TestCase
{
    public function testHandlerMayBeAbsent(): void
    {
        $body = new Identity();
        $node = new TryCatch($body, null);

        self::assertSame($body, $node->body);
        self::assertNull($node->handler);
    }
}
