<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\ObjectEntry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ObjectEntryTest extends TestCase
{
    public function testAlwaysHasKeyAndValue(): void
    {
        $key   = new Literal('a');
        $value = new Identity();
        $entry = new ObjectEntry($key, $value);

        self::assertSame($key, $entry->key);
        self::assertSame($value, $entry->value);
    }
}
