<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\ObjectPatternEntry;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ObjectPatternEntryTest extends TestCase
{
    public function testKeyedEntryHasNoVariable(): void
    {
        $key   = new Literal('a');
        $value = new VariablePattern('x');
        $entry = new ObjectPatternEntry(null, $key, $value);

        self::assertNull($entry->variable);
        self::assertSame($key, $entry->key);
        self::assertSame($value, $entry->value);
    }

    public function testVariableShorthandHasNoKey(): void
    {
        $entry = new ObjectPatternEntry('name', null, null);

        self::assertSame('name', $entry->variable);
        self::assertNull($entry->key);
    }
}
