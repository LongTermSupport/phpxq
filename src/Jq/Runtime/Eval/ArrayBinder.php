<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * The pattern `[p0, p1, ...]`.
 *
 * @internal
 */
final readonly class ArrayBinder implements BinderInterface
{
    /**
     * @param non-empty-list<BinderInterface> $elements
     */
    public function __construct(private array $elements)
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
        if ($index === \count($this->elements)) {
            $continue($env);

            return;
        }

        $this->elements[$index]->bind($env, Access::index($value, $index), function (?Env $next) use ($value, $index, $continue): void {
            $this->step($next, $value, $index + 1, $continue);
        });
    }
}
