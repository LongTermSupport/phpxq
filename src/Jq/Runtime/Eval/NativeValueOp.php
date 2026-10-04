<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;

/**
 * A call of a {@see ValueBuiltinInterface} with generating arguments: nested loops with the last argument outermost.
 *
 * @internal
 */
final class NativeValueOp extends AbstractOp
{
    /**
     * @param list<OpInterface> $arguments
     */
    public function __construct(
        private readonly ValueBuiltinInterface $builtin,
        private readonly array $arguments,
        private readonly RunState $state,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->loop($env, $input, \count($this->arguments) - 1, [], $emit);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function loop(?Env $env, mixed $input, int $index, array $values, Closure $emit): void
    {
        if ($index < 0) {
            ksort($values);
            $emit($this->builtin->call($this->state->context(), $input, ...array_values($values)));

            return;
        }

        $this->arguments[$index]->run($env, $input, function (mixed $value) use ($env, $input, $index, $values, $emit): void {
            $values[$index] = $value;
            $this->loop($env, $input, $index - 1, $values, $emit);
        });
    }
}
