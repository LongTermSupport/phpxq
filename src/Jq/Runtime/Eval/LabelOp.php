<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;
use stdClass;

/**
 * `label $name | body`: a fresh label token is bound for each activation; `break $name` throws it and
 * ends the body's output here.
 *
 * @internal
 */
final readonly class LabelOp implements OpInterface
{
    public function __construct(private OpInterface $body)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $token = new stdClass();
        try {
            $this->body->run(new Env($env, $token), $input, $emit);
        } catch (BreakException $breakException) {
            if ($breakException->label !== $token) {
                throw $breakException;
            }
        }
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $token = new stdClass();
        try {
            $this->body->paths(new Env($env, $token), $path, $input, $emit);
        } catch (BreakException $breakException) {
            if ($breakException->label !== $token) {
                throw $breakException;
            }
        }
    }
}
