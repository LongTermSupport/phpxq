<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ObjectPattern;
use LTS\PhpXq\Jq\Ast\ObjectPatternEntry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ObjectPatternTest extends TestCase
{
    public function testKeepsEntries(): void
    {
        $entries = [new ObjectPatternEntry('a', null, null)];

        self::assertSame($entries, new ObjectPattern($entries)->entries);
    }
}
