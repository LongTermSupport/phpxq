<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Json\JsonObject;

/**
 * `.name`: index of the input by a constant string.
 *
 * @internal
 */
final class FieldOp implements SingleOp
{
    public function __construct(private readonly string $key)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        if ($input instanceof JsonObject) {
            return $input->get($this->key);
        }

        return Access::index($input, $this->key);
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $emit($input instanceof JsonObject ? $input->get($this->key) : Access::index($input, $this->key));
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        if (null === $path) {
            throw PathErrors::access($this->key, $input);
        }

        $path[] = $this->key;
        $emit($path, $this->value($env, $input));
    }
}
