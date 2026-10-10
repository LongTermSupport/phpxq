<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\Eval\CompiledJq;
use LTS\PhpXq\Jq\Runtime\Eval\Core;

/**
 * AST to executable closures (see architecture.md, "Evaluator model"). A compiler keeps its prelude and the
 * modules it has loaded, so compiling several programs with one instance parses and compiles each of them
 * once.
 *
 * @internal
 */
final class Compiler implements CompilerInterface
{
    private ?Core $core = null;

    public function __construct(
        private readonly BuiltinRegistryInterface $builtins,
        private readonly ParserInterface $parser,
        private readonly ModuleLoaderInterface $modules,
    ) {
    }

    public function compile(Program $program, array $globalVariables = []): CompiledProgramInterface
    {
        $core = $this->core ??= new Core($this->builtins, $this->parser, $this->modules);

        return new CompiledJq($core->compileMain($program, ...$globalVariables), $core->state);
    }
}
