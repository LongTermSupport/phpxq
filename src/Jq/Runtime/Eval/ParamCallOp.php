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
final readonly class ParamCallOp implements Op
{
    public function __construct(private int $depth)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $argument = $this->argument($env);
        $argument->op->run($argument->env, $input, $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $argument = $this->argument($env);
        $argument->op->paths($argument->env, $path, $input, $emit);
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
