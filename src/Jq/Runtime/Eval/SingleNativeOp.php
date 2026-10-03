<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\ValueBuiltin;

/**
 * A call of a {@see ValueBuiltin} whose arguments each produce exactly one value.
 *
 * @internal
 */
final class SingleNativeOp extends AbstractSingleOp
{
    /**
     * @param list<SingleOp> $arguments
     */
    public function __construct(
        private readonly ValueBuiltin $builtin,
        private readonly array $arguments,
        private readonly RunState $state,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $values = [];
        foreach ($this->arguments as $argument) {
            $values[] = $argument->value($env, $input);
        }

        return $this->builtin->call($this->state->context(), $input, $values);
    }
}
