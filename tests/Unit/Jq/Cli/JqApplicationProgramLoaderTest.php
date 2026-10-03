<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\ProgramLoader;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationProgramLoaderTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/jqpl-' . bin2hex(random_bytes(4));
        mkdir($this->home);
    }

    protected function tearDown(): void
    {
        if (is_file($this->home . '/.jq')) {
            unlink($this->home . '/.jq');
        }

        rmdir($this->home);
        parent::tearDown();
    }

    public function testParsesTheSource(): void
    {
        $parser  = new JqApplicationFakeParser();
        $program = new ProgramLoader($parser, null)->load('.');

        self::assertNotNull($program->body);
        self::assertSame(['.'], $parser->sources);
    }

    public function testDefinitionsOnlyIsRejected(): void
    {
        $this->expectExceptionObject(new JqCompileException('Top-level program not given (try ".")'));
        new ProgramLoader(new JqApplicationFakeParser(), null)->load('DEFS');
    }

    public function testHomeFileDefinitionsAreAddedBeforeTheProgramsOwn(): void
    {
        file_put_contents($this->home . '/.jq', 'def home: 1;');
        $parser  = new JqApplicationFakeParser();
        $program = new ProgramLoader($parser, $this->home)->load('.');

        self::assertSame(['home'], array_map(static fn (\LTS\PhpXq\Jq\Ast\FuncDef $def): string => $def->name, $program->defs));
        self::assertNotNull($program->body);
        self::assertSame(['.', 'def home: 1;'], $parser->sources);
    }

    public function testHomeWithoutADotJqFileChangesNothing(): void
    {
        $program = new ProgramLoader(new JqApplicationFakeParser(), $this->home)->load('.');

        self::assertSame([], $program->defs);
    }

    public function testEmptyHomeDirectoryIsIgnored(): void
    {
        $program = new ProgramLoader(new JqApplicationFakeParser(), '')->load('.');

        self::assertSame([], $program->defs);
    }

    public function testDotJqDirectoryIsLeftToTheModuleLoader(): void
    {
        mkdir($this->home . '/.jq');

        try {
            $program = new ProgramLoader(new JqApplicationFakeParser(), $this->home)->load('.');
        } finally {
            rmdir($this->home . '/.jq');
        }

        self::assertSame([], $program->defs);
    }

    public function testSyntaxErrorInTheHomeFileIsACompileError(): void
    {
        file_put_contents($this->home . '/.jq', 'SYNTAX');

        $this->expectException(JqCompileException::class);
        new ProgramLoader(new JqApplicationFakeParser(), $this->home)->load('.');
    }
}
