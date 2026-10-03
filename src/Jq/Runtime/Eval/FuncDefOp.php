<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `def f: ...; rest` inside an expression: pushes the environment entry the function body is anchored to,
 * then runs the rest.
 *
 * @internal
 */
final readonly class FuncDefOp implements Op
{
    public function __construct(private Op $rest)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->rest->run(new Env($env, null), $input, $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->rest->paths(new Env($env, null), $path, $input, $emit);
    }
}
