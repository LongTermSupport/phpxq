<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * `not`.
 *
 * @internal
 */
final class NotOp extends AbstractSingleOp
{
    public function value(?Env $env, mixed $input): mixed
    {
        return null === $input || false === $input;
    }
}
