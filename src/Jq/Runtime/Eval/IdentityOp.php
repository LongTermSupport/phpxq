<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `.`.
 *
 * @internal
 */
final class IdentityOp implements SingleOpInterface
{
    public function value(?Env $env, mixed $input): mixed
    {
        return $input;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($input);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $emit($path, $input);
    }
}
