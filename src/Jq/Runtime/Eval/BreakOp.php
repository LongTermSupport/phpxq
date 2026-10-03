<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;

/**
 * `break $name`.
 *
 * @internal
 */
final class BreakOp implements Op
{
    public function __construct(private readonly int $depth)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        throw new BreakException($this->token($env));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        throw new BreakException($this->token($env));
    }

    private function token(?Env $env): object
    {
        $value = Env::at($env, $this->depth)?->value;

        return \is_object($value) ? $value : new \stdClass();
    }
}
