<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * `$name` for a lexically bound variable.
 *
 * @internal
 */
final class VarOp extends AbstractSingleOp
{
    public function __construct(private readonly int $depth)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        for ($depth = $this->depth; $depth > 0; --$depth) {
            $env = $env?->parent;
        }

        return $env?->value;
    }
}
