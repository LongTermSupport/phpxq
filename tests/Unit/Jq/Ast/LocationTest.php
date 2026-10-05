<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\Location;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LocationTest extends TestCase
{
    public function testCarriesFileAndLine(): void
    {
        $location = new Location('<top-level>', 4);

        self::assertSame('<top-level>', $location->file);
        self::assertSame(4, $location->line);
    }
}
