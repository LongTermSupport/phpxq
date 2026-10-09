<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * The stream of program inputs behind `input` and `inputs`. The CLI implements it over the decoded
 * input files / stdin (and, with --slurp / --raw-input, over the slurped value or raw lines).
 *
 * @internal
 */
interface InputProviderInterface
{
    public function hasNext(): bool;

    /**
     * @throws JqException "No more inputs" when exhausted; a JSON syntax error in the next input
     *                     text surfaces here too
     */
    public function next(): mixed;
}
