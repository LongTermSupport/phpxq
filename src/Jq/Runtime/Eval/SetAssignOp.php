<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left = right`: for every output of right (evaluated against the input) the input with every path of left
 * set to that value.
 *
 * @internal
 */
final class SetAssignOp extends AbstractOp
{
    public function __construct(
        private readonly OpInterface $left,
        private readonly OpInterface $right,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $left = $this->left;
        $this->right->run($env, $input, static function (mixed $value) use ($left, $env, $input, $emit): void {
            $emit(Assignment::setAll($input, static fn (): mixed => $value, ...Assignment::collect($left, $env, $input)));
        });
    }
}
