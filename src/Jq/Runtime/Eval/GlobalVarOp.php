<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * `$name` for a variable supplied by the runtime context (`$ENV`, `--arg` names, ...).
 *
 * @internal
 */
final class GlobalVarOp extends AbstractSingleOp
{
    public function __construct(
        private readonly RunState $state,
        private readonly string $name,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return $this->state->global($this->name);
    }
}
