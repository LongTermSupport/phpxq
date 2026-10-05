<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Runtime\LoadedModule;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LoadedModuleTest extends TestCase
{
    public function testPairsProgramWithPath(): void
    {
        $program = new Program([], null, [], null);
        $module  = new LoadedModule($program, '/lib/a.jq');

        self::assertSame($program, $module->program);
        self::assertSame('/lib/a.jq', $module->path);
    }
}
