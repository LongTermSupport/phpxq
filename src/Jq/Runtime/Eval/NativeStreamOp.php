<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;

/**
 * A call of a {@see StreamBuiltinInterface}: its arguments are closures bound to the caller's environment.
 *
 * @internal
 */
final readonly class NativeStreamOp implements OpInterface
{
    /**
     * @param list<OpInterface> $arguments
     */
    public function __construct(
        private StreamBuiltinInterface $builtin,
        private array $arguments,
        private RunState $state,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->builtin->run($this->state->context(), $input, $this->bind($env), $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        if ($this->builtin instanceof PathStreamBuiltinInterface) {
            $this->builtin->runPaths($this->state->context(), $path, $input, $this->bind($env), $emit);

            return;
        }

        $this->builtin->run($this->state->context(), $input, $this->bind($env), static function (mixed $value) use ($emit): void {
            $emit(null, $value);
        });
    }

    /**
     * @return list<BoundFilter>
     */
    private function bind(?Env $env): array
    {
        $filters = [];
        foreach ($this->arguments as $argument) {
            $filters[] = new BoundFilter($argument, $env);
        }

        return $filters;
    }
}
