<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use Closure;

/**
 * A program ready to run against any number of inputs.
 *
 * @api
 */
interface CompiledProgram
{
    /**
     * Run the program on one input value, calling $emit for every output as it is produced (so output
     * written before a later error is still written, as jq does).
     *
     * @param Closure(mixed): void $emit
     *
     * @throws JqException   uncaught jq error
     * @throws HaltException `halt` / `halt_error`
     */
    public function run(RuntimeContext $context, mixed $input, Closure $emit): void;
}
