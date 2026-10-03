<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;

/**
 * Unary minus over a single-valued operand.
 *
 * @internal
 */
final class SingleNegateOp extends AbstractSingleOp
{
    public function __construct(private readonly SingleOpInterface $operand)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return Arithmetic::negate($this->operand->value($env, $input));
    }
}
