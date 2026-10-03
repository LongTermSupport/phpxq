<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `target[index]` where both sides produce exactly one value.
 *
 * @internal
 */
final readonly class SingleIndexOp implements SingleOpInterface
{
    public function __construct(
        private SingleOpInterface $target,
        private SingleOpInterface $index,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $key = $this->index->value($env, $input);

        return Access::index($this->target->value($env, $input), $key);
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($this->value($env, $input));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $key = $this->index->value($env, $input);
        $this->target->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($key, $emit): void {
            if (null === $valuePath) {
                throw PathErrors::access($key, $value);
            }

            $result      = Access::index($value, $key);
            $valuePath[] = $key;
            $emit($valuePath, $result);
        });
    }
}
