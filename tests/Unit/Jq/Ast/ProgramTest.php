<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\ModuleDirective;
use LTS\PhpXq\Jq\Ast\Program;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ProgramTest extends TestCase
{
    public function testLibraryHasNoBody(): void
    {
        $def     = new FuncDef('f', [], new Identity());
        $program = new Program([], new ModuleDirective(null), [$def], null);

        self::assertNull($program->body);
        self::assertSame([$def], $program->defs);
        self::assertInstanceOf(ModuleDirective::class, $program->module);
    }

    public function testMainProgramKeepsBody(): void
    {
        $body = new Identity();

        self::assertSame($body, new Program([], null, [], $body)->body);
    }
}
