<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * One entry of the run-time environment chain. The compiler resolves every variable, closure parameter, nested
 * function definition and label to the number of entries to skip from the innermost one, so the chain mirrors
 * the compile-time {@see Scope} exactly. $value is a jq value (variable), a {@see ClosureArg} (closure
 * parameter), a label token, or null (nested function definition marker).
 *
 * @internal
 */
final readonly class Env
{
    public function __construct(
        public ?self $parent,
        public mixed $value,
    ) {
    }

    public static function at(?self $env, int $depth): ?self
    {
        while ($depth-- > 0) {
            $env = $env?->parent;
        }

        return $env;
    }
}
