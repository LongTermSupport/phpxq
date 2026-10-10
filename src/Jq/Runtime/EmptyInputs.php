<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * The input stream of a program run over a single value: `input` fails with jq's "No more inputs" and
 * `inputs` is empty.
 *
 * @internal
 */
final readonly class EmptyInputs implements InputProviderInterface
{
    public function hasNext(): bool
    {
        return false;
    }

    public function next(): mixed
    {
        throw JqException::fromMessage('No more inputs');
    }
}
