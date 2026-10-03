<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * A "cfunction" style builtin: a pure function from the input value and already-evaluated argument
 * values to one output value. This is the fast path for most builtins (`length`, `keys`, `ltrimstr`,
 * `test`, `strftime`, ...).
 *
 * Each argument expression may itself generate several values. The compiler evaluates the arguments as
 * nested loops with the LAST argument outermost and the first innermost (jq's cfunction convention:
 * `setpath(("a","b"|[.]); (1,2))` yields {a:1},{b:1},{a:2},{b:2}) and calls {@see self::call()} once per
 * combination.
 *
 * @api
 */
interface ValueBuiltinInterface extends BuiltinInterface
{
    /**
     * @param list<mixed> $args one value per declared parameter
     *
     * @throws JqException
     */
    public function call(RuntimeContextInterface $context, mixed $input, array $args): mixed;
}
