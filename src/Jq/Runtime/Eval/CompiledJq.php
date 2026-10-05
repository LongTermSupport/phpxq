<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\CompiledProgramInterface;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;

/**
 * The runnable result of compiling a program. Running installs the context in the shared {@see RunState} for
 * the duration of the call and restores the previous one afterwards, so a program may be run from inside
 * another program of the same compiler.
 *
 * @internal
 */
final readonly class CompiledJq implements CompiledProgramInterface
{
    public function __construct(
        private OpInterface $body,
        private RunState $state,
    ) {
    }

    public function run(RuntimeContextInterface $context, mixed $input, Closure $emit): void
    {
        $previous = $this->state->snapshot();
        $this->state->enter($context, $context->globals());
        try {
            EvaluationStack::run(fn () => $this->body->run(null, $input, $emit));
        } finally {
            $this->state->enter($previous['context'], $previous['globals']);
        }
    }
}
