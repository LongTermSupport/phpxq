<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LogicException;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Parser\ParserInterface;

/**
 * Compiler implementation. OWNER: evaluator-core worker (all of src/Jq/Runtime/Eval/ and this file).
 * Skeleton only.
 *
 * @api
 */
final readonly class Compiler implements CompilerInterface
{
    public function __construct(
        private BuiltinRegistry $builtins,
        private ParserInterface $parser,
        private ModuleLoaderInterface $modules,
    ) {
    }

    public function compile(Program $program, array $globalVariables = []): CompiledProgram
    {
        throw new LogicException(\sprintf(
            'Compiler is not implemented (%s, %s, %s)',
            $this->builtins::class,
            $this->parser::class,
            $this->modules::class,
        ));
    }
}
