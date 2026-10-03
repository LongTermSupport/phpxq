<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\CompiledProgram;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;

/**
 * The runnable result of compiling a program. Running installs the context in the shared {@see RunState} for
 * the duration of the call and restores the previous one afterwards, so a program may be run from inside
 * another program of the same compiler.
 *
 * @internal
 */
final readonly class CompiledJq implements CompiledProgram
{
    public function __construct(
        private Op $body,
        private RunState $state,
    ) {
    }

    public function run(RuntimeContext $context, mixed $input, Closure $emit): void
    {
        $previous = $this->state->snapshot();
        $this->state->enter($context, $context->globals());
        try {
            $this->body->run(null, $input, $emit);
        } finally {
            $this->state->enter($previous['context'], $previous['globals']);
        }
    }
}
