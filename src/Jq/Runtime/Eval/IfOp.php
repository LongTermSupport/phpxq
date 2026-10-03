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
final readonly class IfOp implements Op
{
    private ?SingleOp $conditionSingle;

    public function __construct(
        private Op $condition,
        private Op $then,
        private ?Op $else,
    ) {
        $this->conditionSingle = $condition instanceof SingleOp ? $condition : null;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $then = $this->then;
        $else = $this->else;
        if ($this->conditionSingle instanceof SingleOp) {
            $condition = $this->conditionSingle->value($env, $input);
            if (null !== $condition && false !== $condition) {
                $then->run($env, $input, $emit);
            } elseif (!$else instanceof Op) {
                $emit($input);
            } else {
                $else->run($env, $input, $emit);
            }

            return;
        }

        $this->condition->run($env, $input, static function (mixed $condition) use ($then, $else, $env, $input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $then->run($env, $input, $emit);
            } elseif (!$else instanceof Op) {
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
            } elseif (!$else instanceof Op) {
                $emit($path, $input);
            } else {
                $else->paths($env, $path, $input, $emit);
            }
        });
    }
}
