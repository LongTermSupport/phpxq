<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * A closure parameter binding: the argument expression and the environment it was written in.
 *
 * @internal
 */
final readonly class ClosureArg
{
    public function __construct(
        public OpInterface $op,
        public ?Env $env,
    ) {
    }
}
