<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `if` whose condition and both branches produce exactly one value.
 *
 * @internal
 */
final readonly class SingleIfOp implements SingleOpInterface
{
    public function __construct(
        private SingleOpInterface $condition,
        private SingleOpInterface $then,
        private ?SingleOpInterface $else,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $condition = $this->condition->value($env, $input);
        if (null !== $condition && false !== $condition) {
            return $this->then->value($env, $input);
        }

        return $this->else instanceof SingleOpInterface ? $this->else->value($env, $input) : $input;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($this->value($env, $input));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $condition = $this->condition->value($env, $input);
        if (null !== $condition && false !== $condition) {
            $this->then->paths($env, $path, $input, $emit);
        } elseif (!$this->else instanceof SingleOpInterface) {
            $emit($path, $input);
        } else {
            $this->else->paths($env, $path, $input, $emit);
        }
    }
}
