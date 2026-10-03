<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\Filter;

/**
 * An {@see Op} together with its environment: the {@see Filter} handed to native builtins.
 *
 * @internal
 */
final readonly class BoundFilter implements Filter
{
    public function __construct(
        private Op $op,
        private ?Env $env,
    ) {
    }

    public function run(mixed $input, Closure $emit): void
    {
        $this->op->run($this->env, $input, $emit);
    }

    public function paths(?array $path, mixed $input, Closure $emit): void
    {
        $this->op->paths($this->env, $path, $input, $emit);
    }
}
