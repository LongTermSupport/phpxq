<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left, right`.
 *
 * @internal
 */
final class CommaOp implements Op
{
    public function __construct(
        private readonly Op $left,
        private readonly Op $right,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->left->run($env, $input, $emit);
        $this->right->run($env, $input, $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->left->paths($env, $path, $input, $emit);
        $this->right->paths($env, $path, $input, $emit);
    }
}
