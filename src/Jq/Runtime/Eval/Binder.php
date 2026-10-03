<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * A compiled destructuring pattern. Binding pushes one {@see Env} entry per variable occurrence, in source
 * order, and hands the extended environment to the continuation once for every combination of values the
 * pattern's key expressions generate.
 *
 * @internal
 */
interface Binder
{
    /**
     * @param Closure(?Env): void $continue
     */
    public function bind(?Env $env, mixed $value, Closure $continue): void;
}
