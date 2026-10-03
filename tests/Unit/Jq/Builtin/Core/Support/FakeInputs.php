<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support;

use LTS\PhpXq\Jq\Runtime\InputPositionInterface;
use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * A queue of input values that also knows the line it is on.
 *
 * @internal
 */
final class FakeInputs implements InputProviderInterface, InputPositionInterface
{
    /**
     * @param list<mixed> $queue
     */
    public function __construct(
        private array $queue,
        private readonly int $line,
    ) {
    }

    public function lineNumber(): int
    {
        return $this->line;
    }

    public function hasNext(): bool
    {
        return [] !== $this->queue;
    }

    public function next(): mixed
    {
        if ([] === $this->queue) {
            throw new JqException('No more inputs');
        }

        return array_shift($this->queue);
    }
}
