<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Iterate;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class IterateTest extends TestCase
{
    public function testCarriesTarget(): void
    {
        $target = new Identity();

        self::assertSame($target, new Iterate($target)->target);
    }
}
