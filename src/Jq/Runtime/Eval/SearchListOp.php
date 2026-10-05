<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * `get_search_list`: the library search path (`-L`).
 *
 * @internal
 */
final class SearchListOp extends AbstractSingleOp
{
    public function __construct(private readonly RunState $state)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return $this->state->context()->libraryPaths();
    }
}
