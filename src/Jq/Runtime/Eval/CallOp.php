<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * A call of a jq-defined function. $depth locates the environment entry of a nested definition; a top-level
 * function has no captured environment (depth -1). Closure arguments are bound to the caller's environment.
 *
 * @internal
 */
final readonly class CallOp implements OpInterface
{
    /**
     * @param list<OpInterface> $arguments
     */
    public function __construct(
        private FuncInfo $function,
        private int $depth,
        private array $arguments,
        private RunState $state,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->state->enterCall();

        try {
            $this->function->body()->run($this->frame($env), $input, $emit);
        } finally {
            $this->state->leaveCall();
        }
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->state->enterCall();

        try {
            $this->function->body()->paths($this->frame($env), $path, $input, $emit);
        } finally {
            $this->state->leaveCall();
        }
    }

    private function frame(?Env $env): ?Env
    {
        $frame = $this->depth < 0 ? null : Env::at($env, $this->depth);
        foreach ($this->arguments as $argument) {
            $frame = new Env($frame, new ClosureArg($argument, $env));
        }

        return $frame;
    }
}
