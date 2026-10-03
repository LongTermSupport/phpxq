<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * Implemented by an {@see InputProviderInterface} that knows how far into its current input it is, so
 * `input_line_number` can be answered: `$context->inputs() instanceof InputPositionInterface`.
 *
 * @api
 */
interface InputPositionInterface
{
    /**
     * Newlines consumed so far in the file (or stdin) the most recent input value came from; 0 before
     * any input has been read.
     */
    public function lineNumber(): int;
}
