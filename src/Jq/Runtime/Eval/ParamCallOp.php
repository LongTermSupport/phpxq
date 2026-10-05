<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LogicException;

/**
 * A call of a closure parameter: runs the argument expression in the environment it was written in.
 *
 * @internal
 */
final readonly class ParamCallOp implements OpInterface
{
    public function __construct(
        private int $depth,
        private RunState $state,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $argument = $this->argument($env);
        $this->state->enterCall();

        try {
            $argument->op->run($argument->env, $input, $emit);
        } finally {
            $this->state->leaveCall();
        }
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $argument = $this->argument($env);
        $this->state->enterCall();

        try {
            $argument->op->paths($argument->env, $path, $input, $emit);
        } finally {
            $this->state->leaveCall();
        }
    }

    private function argument(?Env $env): ClosureArg
    {
        for ($depth = $this->depth; $depth > 0; --$depth) {
            $env = $env?->parent;
        }

        $argument = $env?->value;
        if (!$argument instanceof ClosureArg) {
            throw new LogicException('Closure parameter is not bound');
        }

        return $argument;
    }
}
