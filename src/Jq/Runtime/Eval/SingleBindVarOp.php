<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `source as $name | body` where both source and body produce exactly one value.
 *
 * @internal
 */
final class SingleBindVarOp implements SingleOp
{
    public function __construct(
        private readonly SingleOp $source,
        private readonly SingleOp $body,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return $this->body->value(new Env($env, $this->source->value($env, $input)), $input);
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($this->value($env, $input));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->body->paths(new Env($env, $this->source->value($env, $input)), $path, $input, $emit);
    }
}
