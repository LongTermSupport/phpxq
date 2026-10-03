<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ModuleDirective;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ModuleDirectiveTest extends TestCase
{
    public function testCarriesMetadata(): void
    {
        self::assertSame(['version' => 1], new ModuleDirective(['version' => 1])->metadata);
    }
}
