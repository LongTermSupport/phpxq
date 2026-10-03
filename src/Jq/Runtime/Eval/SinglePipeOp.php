<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left | right` where both sides produce exactly one value.
 *
 * @internal
 */
final readonly class SinglePipeOp implements SingleOpInterface
{
    public function __construct(
        private SingleOpInterface $left,
        private SingleOpInterface $right,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return $this->right->value($env, $this->left->value($env, $input));
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($this->right->value($env, $this->left->value($env, $input)));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $right = $this->right;
        $this->left->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($right, $env, $emit): void {
            $right->paths($env, $valuePath, $value, $emit);
        });
    }
}
