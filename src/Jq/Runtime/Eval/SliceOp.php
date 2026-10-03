<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Json\JsonObject;

/**
 * `target[from:to]`. Bounds are evaluated against the original input (from is the outer loop), the target
 * is the innermost loop.
 *
 * @internal
 */
final class SliceOp implements Op
{
    public function __construct(
        private readonly Op $target,
        private readonly ?Op $from,
        private readonly ?Op $to,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $target = $this->target;
        $this->bounds($env, $input, static function (mixed $from, mixed $to) use ($target, $env, $input, $emit): void {
            $target->run($env, $input, static function (mixed $value) use ($from, $to, $emit): void {
                $emit(Access::slice($value, $from, $to));
            });
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $target = $this->target;
        $this->bounds($env, $input, static function (mixed $from, mixed $to) use ($target, $env, $path, $input, $emit): void {
            $key = new JsonObject(['start' => $from, 'end' => $to]);
            $target->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($from, $to, $key, $emit): void {
                if (null === $valuePath) {
                    throw PathErrors::access($key, $value);
                }

                $result = Access::slice($value, $from, $to);
                $valuePath[] = $key;
                $emit($valuePath, $result);
            });
        });
    }

    /**
     * @param Closure(mixed, mixed): void $each
     */
    private function bounds(?Env $env, mixed $input, Closure $each): void
    {
        $to = $this->to;
        $evaluateTo = static function (mixed $from) use ($to, $env, $input, $each): void {
            if (null === $to) {
                $each($from, null);

                return;
            }

            $to->run($env, $input, static function (mixed $end) use ($from, $each): void {
                $each($from, $end);
            });
        };

        if (null === $this->from) {
            $evaluateTo(null);

            return;
        }

        $this->from->run($env, $input, $evaluateTo);
    }
}
