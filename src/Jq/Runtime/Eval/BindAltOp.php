<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * `source as p1 ?// p2 ?// ... | body`. Every variable of every alternative is bound in the body (null when
 * the matching alternative lacks it). An error while destructuring or inside the body moves on to the next
 * alternative; the last alternative's error propagates, and so does an error raised by the continuation.
 *
 * @internal
 */
final readonly class BindAltOp implements OpInterface
{
    /**
     * @param non-empty-list<BinderInterface> $binders
     * @param non-empty-list<list<string>>    $events    variable name of each binding event, per alternative
     * @param list<string>                    $variables canonical variable order seen by the body
     */
    public function __construct(
        private OpInterface $source,
        private array $binders,
        private array $events,
        private array $variables,
        private OpInterface $body,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->source->run($env, $input, function (mixed $value) use ($env, $input, $emit): void {
            $this->alternatives($env, $value, static function (OpInterface $body, ?Env $bound, Downstream $downstream) use ($input, $emit): void {
                $body->run($bound, $input, $downstream->guard($emit));
            });
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->source->run($env, $input, function (mixed $value) use ($env, $path, $input, $emit): void {
            $this->alternatives($env, $value, static function (OpInterface $body, ?Env $bound, Downstream $downstream) use ($path, $input, $emit): void {
                $body->paths($bound, $path, $input, $downstream->guardPaths($emit));
            });
        });
    }

    /**
     * @param Closure(OpInterface, ?Env, Downstream): void $runBody
     */
    private function alternatives(?Env $env, mixed $value, Closure $runBody): void
    {
        $last = \count($this->binders) - 1;
        foreach ($this->binders as $index => $binder) {
            $downstream = new Downstream();
            $names      = $this->events[$index];
            try {
                $binder->bind($env, $value, function (?Env $matched) use ($env, $names, $runBody, $downstream): void {
                    $runBody($this->body, $this->canonical($env, $matched, $names), $downstream);
                });

                return;
            } catch (JqException $exception) {
                if ($downstream->active() || $index === $last) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Rebuild the environment with the canonical variable layout from the entries one alternative pushed.
     *
     * @param list<string> $names
     */
    private function canonical(?Env $base, ?Env $matched, array $names): ?Env
    {
        $seen = [];
        $walk = $matched;
        for ($i = \count($names) - 1; $i >= 0; --$i) {
            $name = $names[$i];
            if (!\array_key_exists($name, $seen) && $walk instanceof Env) {
                $seen[$name] = $walk->value;
            }

            $walk = $walk?->parent;
        }

        $bound = $base;
        foreach ($this->variables as $variable) {
            $bound = new Env($bound, $seen[$variable] ?? null);
        }

        return $bound;
    }
}
