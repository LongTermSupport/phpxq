<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * A compiled AST node. Unlike {@see \LTS\PhpXq\Jq\Runtime\FilterInterface} it is not bound to an environment: the
 * environment is an argument, so one Op tree is shared by every activation of the function it belongs to.
 *
 * @internal
 */
interface OpInterface
{
    /**
     * @param Closure(mixed): void $emit
     *
     * @throws JqException
     * @throws BreakException
     */
    public function run(?Env $env, mixed $input, Closure $emit): void;

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     *
     * @throws JqException
     * @throws BreakException
     */
    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void;
}
