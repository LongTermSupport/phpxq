<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left | right`.
 *
 * @internal
 */
final readonly class PipeOp implements OpInterface
{
    public function __construct(
        private OpInterface $left,
        private OpInterface $right,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $right = $this->right;
        $this->left->run($env, $input, static function (mixed $value) use ($right, $env, $emit): void {
            $right->run($env, $value, $emit);
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $right = $this->right;
        $this->left->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($right, $env, $emit): void {
            $right->paths($env, $valuePath, $value, $emit);
        });
    }
}
