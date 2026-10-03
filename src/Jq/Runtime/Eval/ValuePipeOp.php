<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left | right` where left produces exactly one value, so no continuation closure is needed.
 *
 * @internal
 */
final readonly class ValuePipeOp implements Op
{
    public function __construct(
        private SingleOp $left,
        private Op $right,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->right->run($env, $this->left->value($env, $input), $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $right = $this->right;
        $this->left->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($right, $env, $emit): void {
            $right->paths($env, $valuePath, $value, $emit);
        });
    }
}
