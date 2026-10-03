<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Cli\CompilerFactoryInterface;
use LTS\PhpXq\Jq\Runtime\CompiledProgram;
use LTS\PhpXq\Jq\Runtime\CompilerInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;

/**
 * Compiler factory and compiler in one: every program compiles to the same closure, and what the CLI
 * handed over (library paths, global variable names, the AST) is remembered.
 *
 * @internal
 */
final class JqApplicationFakeCompiler implements CompilerFactoryInterface, CompilerInterface
{
    /** @var list<string> */
    public array $libraryPaths = [];

    /** @var list<string> */
    public array $globalNames = [];

    public ?Program $program = null;

    /**
     * @param Closure(RuntimeContext, mixed, Closure(mixed): void): void $behaviour
     */
    public function __construct(
        private readonly Closure $behaviour,
    ) {
    }

    public function create(array $libraryPaths): CompilerInterface
    {
        $this->libraryPaths = $libraryPaths;

        return $this;
    }

    public function compile(Program $program, array $globalVariables = []): CompiledProgram
    {
        $this->program     = $program;
        $this->globalNames = $globalVariables;

        return new JqApplicationFakeProgram($this->behaviour);
    }
}
