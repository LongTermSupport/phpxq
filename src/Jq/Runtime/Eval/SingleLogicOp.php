<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * `and` / `or` over single-valued operands.
 *
 * @internal
 */
final class SingleLogicOp extends AbstractSingleOp
{
    public function __construct(
        private readonly SingleOpInterface $left,
        private readonly SingleOpInterface $right,
        private readonly bool $isAnd,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $leftValue = $this->left->value($env, $input);
        $truthy    = null !== $leftValue && false !== $leftValue;
        if ($truthy !== $this->isAnd) {
            return $truthy;
        }

        $rightValue = $this->right->value($env, $input);

        return null !== $rightValue && false !== $rightValue;
    }
}
