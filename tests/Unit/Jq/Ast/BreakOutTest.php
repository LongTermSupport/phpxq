<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\BreakOut;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BreakOutTest extends TestCase
{
    public function testCarriesLabelAndLine(): void
    {
        $break = new BreakOut('out', 2);

        self::assertSame('out', $break->label);
        self::assertSame(2, $break->line);
    }
}
