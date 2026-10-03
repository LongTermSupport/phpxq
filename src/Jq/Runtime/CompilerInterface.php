<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Jq\Ast\Program;

/**
 * AST to executable closures. Implementations are constructed with a {@see BuiltinRegistry}, a
 * {@see \LTS\PhpXq\Jq\Parser\ParserInterface} (for the prelude) and a {@see ModuleLoaderInterface}.
 *
 * Resolves every name at compile time: functions by name/arity (scope, then prelude, then natives),
 * variables, labels, and imports. All of those are compile errors, never run-time ones.
 *
 * @api
 */
interface CompilerInterface
{
    /**
     * @param list<string> $globalVariables names (without `$`) of the variables the runtime context will
     *                                      supply: ENV, __prog_args, ARGS and each `--arg` name
     *
     * @throws JqCompileException
     */
    public function compile(Program $program, array $globalVariables = []): CompiledProgram;
}
