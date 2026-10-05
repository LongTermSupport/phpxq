<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `select(condition)`: the input once for every truthy output of the condition. Valid in path expressions.
 *
 * @internal
 */
final readonly class SelectOp implements OpInterface
{
    private ?SingleOpInterface $single;

    public function __construct(private OpInterface $condition)
    {
        $this->single = $condition instanceof SingleOpInterface ? $condition : null;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        if ($this->single instanceof SingleOpInterface) {
            $result = $this->single->value($env, $input);
            if (null !== $result && false !== $result) {
                $emit($input);
            }

            return;
        }

        $this->condition->run($env, $input, static function (mixed $result) use ($input, $emit): void {
            if (null !== $result && false !== $result) {
                $emit($input);
            }
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->condition->run($env, $input, static function (mixed $result) use ($path, $input, $emit): void {
            if (null !== $result && false !== $result) {
                $emit($path, $input);
            }
        });
    }
}
