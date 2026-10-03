<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `empty`.
 *
 * @internal
 */
final class EmptyOp implements Op
{
    public function run(?Env $env, mixed $input, Closure $emit): void
    {
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
    }
}
