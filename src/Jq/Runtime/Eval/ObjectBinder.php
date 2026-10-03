<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * The pattern `{...}`. Each entry is `[variable, key, value]`: the variable name bound to the member (or
 * null), the key expression (null: the variable name is the key) and the sub-pattern for the member (or
 * null).
 *
 * @internal
 */
final readonly class ObjectBinder implements BinderInterface
{
    /**
     * @param non-empty-list<array{?string, ?OpInterface, ?BinderInterface}> $entries
     */
    public function __construct(private array $entries)
    {
    }

    public function bind(?Env $env, mixed $value, Closure $continue): void
    {
        $this->step($env, $value, 0, $continue);
    }

    /**
     * @param Closure(?Env): void $continue
     */
    private function step(?Env $env, mixed $value, int $index, Closure $continue): void
    {
        if ($index === \count($this->entries)) {
            $continue($env);

            return;
        }

        [$variable, $keyOp, $binder]  = $this->entries[$index];
        $withKey                      = function (mixed $key) use ($variable, $binder, $env, $value, $index, $continue): void {
            $member = Access::index($value, $key);
            $inner  = null === $variable ? $env : new Env($env, $member);
            if (!$binder instanceof BinderInterface) {
                $this->step($inner, $value, $index + 1, $continue);

                return;
            }

            $binder->bind($inner, $member, function (?Env $next) use ($value, $index, $continue): void {
                $this->step($next, $value, $index + 1, $continue);
            });
        };

        if (!$keyOp instanceof OpInterface) {
            $withKey($variable);

            return;
        }

        $keyOp->run($env, $value, $withKey);
    }
}
