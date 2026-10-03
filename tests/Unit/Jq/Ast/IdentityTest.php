<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class IdentityTest extends TestCase
{
    public function testInstancesAreInterchangeable(): void
    {
        $first  = new Identity();
        $second = new Identity();

        self::assertEquals($first, $second);
        self::assertNotSame($first, $second);
    }
}
