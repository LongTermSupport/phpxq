<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `left op= right` (`+=`, `-=`, `*=`, `/=`, `%=`, `//=`): for every output of right, evaluated once against
 * the input, every path of left is replaced by `old op right`.
 *
 * @internal
 */
final class ArithAssignOp extends AbstractOp
{
    /**
     * @param Closure(mixed, mixed): mixed $operation receives the old value and the right-hand value
     */
    public function __construct(
        private readonly OpInterface $left,
        private readonly OpInterface $right,
        private readonly Closure $operation,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $left      = $this->left;
        $operation = $this->operation;
        $this->right->run($env, $input, static function (mixed $operand) use ($left, $operation, $env, $input, $emit): void {
            $emit(Assignment::setAll(
                $input,
                static fn (mixed $old): mixed => $operation($old, $operand),
                ...Assignment::collect($left, $env, $input),
            ));
        });
    }
}
