<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left and right` / `left or right`: the left operand is the outer loop, and the right one is only
 * evaluated when the left does not decide the result.
 *
 * @internal
 */
final class LogicOp extends AbstractOp
{
    /**
     * @param bool $isAnd true for `and`, false for `or`
     */
    public function __construct(
        private readonly Op $left,
        private readonly Op $right,
        private readonly bool $isAnd,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $right   = $this->right;
        $isAnd   = $this->isAnd;
        $this->left->run($env, $input, static function (mixed $leftValue) use ($right, $isAnd, $env, $input, $emit): void {
            $truthy = null !== $leftValue && false !== $leftValue;
            if ($truthy !== $isAnd) {
                $emit($truthy);

                return;
            }

            $right->run($env, $input, static function (mixed $rightValue) use ($emit): void {
                $emit(null !== $rightValue && false !== $rightValue);
            });
        });
    }
}
