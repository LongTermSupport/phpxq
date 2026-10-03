<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * A compiled AST node. Unlike {@see \LTS\PhpXq\Jq\Runtime\Filter} it is not bound to an environment: the
 * environment is an argument, so one Op tree is shared by every activation of the function it belongs to.
 *
 * @internal
 */
interface Op
{
    /**
     * @param Closure(mixed): void $emit
     *
     * @throws JqException
     */
    public function run(?Env $env, mixed $input, Closure $emit): void;

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     *
     * @throws JqException
     */
    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void;
}
