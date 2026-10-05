<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * The `source as pattern` half shared by the fold operators: the source runs in value mode and every
 * output is destructured by the pattern, handing each resulting environment to the continuation.
 *
 * @internal
 */
final readonly class SourceBindings
{
    private function __construct()
    {
    }

    /**
     * @param Closure(?Env): void $onBound
     */
    public static function each(OpInterface $source, BinderInterface $binder, ?Env $env, mixed $input, Closure $onBound): void
    {
        $source->run($env, $input, static function (mixed $item) use ($binder, $env, $onBound): void {
            $binder->bind($env, $item, $onBound);
        });
    }
}
