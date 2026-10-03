<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * The pattern `$name`.
 *
 * @internal
 */
final class VarBinder implements BinderInterface
{
    public function bind(?Env $env, mixed $value, Closure $continue): void
    {
        $continue(new Env($env, $value));
    }
}
