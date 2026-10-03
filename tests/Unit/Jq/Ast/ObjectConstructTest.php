<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\ObjectConstruct;
use LTS\PhpXq\Jq\Ast\ObjectEntry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ObjectConstructTest extends TestCase
{
    public function testKeepsEntriesInSourceOrder(): void
    {
        $first  = new ObjectEntry(new Literal('a'), new Identity());
        $second = new ObjectEntry(new Literal('b'), new Identity());

        self::assertSame([$first, $second], new ObjectConstruct([$first, $second])->entries);
    }
}
