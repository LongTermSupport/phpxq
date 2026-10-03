<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Pipe;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Cli\ProgramDump;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationProgramDumpTest extends TestCase
{
    public function testListsTheDefinitionsTheProgramReaches(): void
    {
        $program = new Program([], null, [
            new FuncDef('foo', [], new Identity()),
            new FuncDef('f', [], new Identity()),
            new FuncDef('f', [], new Identity()),
            new FuncDef('g', [], new Identity()),
            new FuncDef('fg', [], new Pipe(new FunctionCall('f'), new FunctionCall('g'))),
        ], new FunctionCall('fg'));

        self::assertSame(['TOP', 'fg/0:', 'f/0:', 'g/0:'], ProgramDump::lines($program));
    }

    public function testArityIsPartOfTheName(): void
    {
        $program = new Program([], null, [
            new FuncDef('f', [], new Identity()),
            new FuncDef('f', ['x'], new Identity()),
        ], new FunctionCall('f', [new Identity()]));

        self::assertSame(['TOP', 'f/1:'], ProgramDump::lines($program));
    }

    public function testRecursionTerminates(): void
    {
        $program = new Program([], null, [
            new FuncDef('r', [], new FunctionCall('r')),
        ], new FunctionCall('r'));

        self::assertSame(['TOP', 'r/0:'], ProgramDump::lines($program));
    }

    public function testNoBodyNoDefinitions(): void
    {
        self::assertSame(['TOP'], ProgramDump::lines(new Program([], null, [new FuncDef('f', [], new Identity())], null)));
    }
}
