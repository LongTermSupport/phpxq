<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * An arithmetic or comparison operator whose operands each produce exactly one value.
 *
 * @internal
 */
final class SingleOperatorOp extends AbstractSingleOp
{
    /**
     * @param Closure(mixed, mixed): mixed $operation
     */
    public function __construct(
        private readonly SingleOpInterface $left,
        private readonly SingleOpInterface $right,
        private readonly Closure $operation,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $rightValue = $this->right->value($env, $input);

        return ($this->operation)($this->left->value($env, $input), $rightValue);
    }
}
