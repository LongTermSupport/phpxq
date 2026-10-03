<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * `error` (the input is the error value) and `error(message)` (every output of message is raised).
 *
 * @internal
 */
final class ErrorOp extends AbstractOp
{
    public function __construct(private readonly ?Op $message)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        if (!$this->message instanceof Op) {
            throw new JqException($input);
        }

        $this->message->run($env, $input, static function (mixed $value): never {
            throw new JqException($value);
        });
    }
}
