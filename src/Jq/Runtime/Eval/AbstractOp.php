<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * Base for generator ops that are not path-aware: path mode reports every output with a null path.
 *
 * @internal
 */
abstract class AbstractOp implements Op
{
    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->run($env, $input, static function (mixed $value) use ($emit): void {
            $emit(null, $value);
        });
    }
}
