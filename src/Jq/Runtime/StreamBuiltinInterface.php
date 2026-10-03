<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use Closure;

/**
 * A native builtin that may emit zero or many outputs and/or takes closure parameters: `empty`, `error`,
 * `range/2`, `limit/2`, `first/1`, `isempty/1`, `input`, `inputs`, `path/1`, `paths`, ...
 *
 * $args are bound {@see FilterInterface}s, one per declared parameter, un-evaluated (closure semantics); a
 * builtin wanting values runs them itself. Stop early with {@see BreakException} using a private label.
 *
 * @api
 */
interface StreamBuiltinInterface extends BuiltinInterface
{
    /**
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     *
     * @throws JqException
     */
    public function run(RuntimeContextInterface $context, mixed $input, array $args, Closure $emit): void;
}
