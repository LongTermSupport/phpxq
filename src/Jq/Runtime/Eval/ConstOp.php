<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * A constant: literals, numbers, `$__loc__`, imported data.
 *
 * @internal
 */
final class ConstOp extends AbstractSingleOp
{
    public function __construct(public readonly mixed $constant)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return $this->constant;
    }
}
