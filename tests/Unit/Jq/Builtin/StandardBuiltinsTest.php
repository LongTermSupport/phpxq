<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use LTS\PhpXq\Jq\Builtin\StandardBuiltins;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StandardBuiltinsTest extends TestCase
{
    public function testCreateReturnsAFreshRegistryEachTime(): void
    {
        $first  = StandardBuiltins::create();
        $second = StandardBuiltins::create();

        self::assertNotSame($first, $second);
        self::assertNull($first->lookup('no_such_builtin', 0));
    }
}
