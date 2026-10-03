<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `if cond then a else b end`; a missing else branch is the identity. The condition is evaluated in value
 * mode even inside a path expression.
 *
 * @internal
 */
final class IfOp implements Op
{
    private readonly ?SingleOp $conditionSingle;

    public function __construct(
        private readonly Op $condition,
        private readonly Op $then,
        private readonly ?Op $else,
    ) {
        $this->conditionSingle = $condition instanceof SingleOp ? $condition : null;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $then = $this->then;
        $else = $this->else;
        if (null !== $this->conditionSingle) {
            $condition = $this->conditionSingle->value($env, $input);
            if (null !== $condition && false !== $condition) {
                $then->run($env, $input, $emit);
            } elseif (null === $else) {
                $emit($input);
            } else {
                $else->run($env, $input, $emit);
            }

            return;
        }

        $this->condition->run($env, $input, static function (mixed $condition) use ($then, $else, $env, $input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $then->run($env, $input, $emit);
            } elseif (null === $else) {
                $emit($input);
            } else {
                $else->run($env, $input, $emit);
            }
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $then = $this->then;
        $else = $this->else;
        $this->condition->run($env, $input, static function (mixed $condition) use ($then, $else, $env, $path, $input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $then->paths($env, $path, $input, $emit);
            } elseif (null === $else) {
                $emit($path, $input);
            } else {
                $else->paths($env, $path, $input, $emit);
            }
        });
    }
}
