<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use LTS\PhpXq\Jq\Runtime\Eval\SearchListOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SearchListOp::class)]
final class SearchListOpTest extends OpTestCase
{
    public function testReturnsTheContextsLibraryPaths(): void
    {
        $state = new RunState();
        $state->enter(new StubContext([], ['/a', '/b']), []);

        self::assertSame([['/a', '/b']], self::outputs(new SearchListOp($state)));
    }
}
