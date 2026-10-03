<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `target[index]` where either side may generate several values. The index expression is evaluated against
 * the original input as the outer loop; the target is the inner loop.
 *
 * @internal
 */
final readonly class IndexOp implements Op
{
    public function __construct(
        private Op $target,
        private Op $index,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $target = $this->target;
        $this->index->run($env, $input, static function (mixed $key) use ($target, $env, $input, $emit): void {
            $target->run($env, $input, static function (mixed $value) use ($key, $emit): void {
                $emit(Access::index($value, $key));
            });
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $target = $this->target;
        $this->index->run($env, $input, static function (mixed $key) use ($target, $env, $path, $input, $emit): void {
            $target->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($key, $emit): void {
                if (null === $valuePath) {
                    throw PathErrors::access($key, $value);
                }

                $result      = Access::index($value, $key);
                $valuePath[] = $key;
                $emit($valuePath, $result);
            });
        });
    }
}
