<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * `try body catch handler` and the postfix `body?` (no handler). Only errors raised by the body itself are
 * caught: an exception thrown while the continuation runs inside $emit is not the body's error.
 *
 * @internal
 */
final class TryOp implements Op
{
    public function __construct(
        private readonly Op $body,
        private readonly ?Op $handler,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $downstream = new Downstream();
        try {
            $this->body->run($env, $input, $downstream->guard($emit));
        } catch (JqException $exception) {
            if ($downstream->active()) {
                throw $exception;
            }

            $this->handler?->run($env, $exception->value, $emit);
        }
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $downstream = new Downstream();
        try {
            $this->body->paths($env, $path, $input, $downstream->guardPaths($emit));
        } catch (JqException $exception) {
            if ($downstream->active()) {
                throw $exception;
            }

            $this->handler?->paths($env, null, $exception->value, $emit);
        }
    }
}
