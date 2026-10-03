<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * Base for single-output ops that are not path-aware: path mode reports the value with a null path.
 *
 * @internal
 */
abstract class AbstractSingleOp implements SingleOp
{
    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($this->value($env, $input));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $emit(null, $this->value($env, $input));
    }
}
