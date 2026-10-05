<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ArrayConstruct;
use LTS\PhpXq\Jq\Ast\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ArrayConstructTest extends TestCase
{
    public function testEmptyArrayHasNullBody(): void
    {
        $body = new Identity();

        self::assertNull(new ArrayConstruct(null)->body);
        self::assertSame($body, new ArrayConstruct($body)->body);
    }
}
