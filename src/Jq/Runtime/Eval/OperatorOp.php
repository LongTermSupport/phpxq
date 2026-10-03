<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * An arithmetic or comparison operator over generators: the right operand is the outer loop, the left the
 * inner one, so `[(1,2) + (10,20)]` is `[11,12,21,22]`.
 *
 * @internal
 */
final class OperatorOp extends AbstractOp
{
    private readonly ?SingleOp $leftSingle;

    private readonly ?SingleOp $rightSingle;

    /**
     * @param Closure(mixed, mixed): mixed $operation
     */
    public function __construct(
        private readonly Op $left,
        private readonly Op $right,
        private readonly Closure $operation,
    ) {
        $this->leftSingle  = $left instanceof SingleOp ? $left : null;
        $this->rightSingle = $right instanceof SingleOp ? $right : null;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $operation = $this->operation;
        $left      = $this->left;
        if ($this->rightSingle instanceof SingleOp) {
            $rightValue = $this->rightSingle->value($env, $input);
            $left->run($env, $input, static function (mixed $leftValue) use ($operation, $rightValue, $emit): void {
                $emit($operation($leftValue, $rightValue));
            });

            return;
        }

        $leftSingle = $this->leftSingle;
        $this->right->run($env, $input, static function (mixed $rightValue) use ($operation, $left, $leftSingle, $env, $input, $emit): void {
            if ($leftSingle instanceof SingleOp) {
                $emit($operation($leftSingle->value($env, $input), $rightValue));

                return;
            }

            $left->run($env, $input, static function (mixed $leftValue) use ($operation, $rightValue, $emit): void {
                $emit($operation($leftValue, $rightValue));
            });
        });
    }
}
