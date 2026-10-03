<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support;

use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;

/**
 * A recording {@see RuntimeContext} for builtin tests.
 *
 * @internal
 */
final class FakeContext implements RuntimeContext
{
    /** @var list<mixed> */
    public array $debugged = [];

    /** @var list<mixed> */
    public array $stderr = [];

    /**
     * @param list<mixed>          $inputs
     * @param array<string, mixed> $globals
     * @param list<string>         $libraryPaths
     */
    public function __construct(
        private readonly array $inputs = [],
        private readonly array $globals = [],
        private readonly ?string $filename = null,
        private readonly array $libraryPaths = [],
    ) {
    }

    public function inputs(): InputProviderInterface
    {
        return new class($this->inputs) implements InputProviderInterface {
            /**
             * @param list<mixed> $queue
             */
            public function __construct(private array $queue)
            {
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
        };
    }

    public function globals(): array
    {
        return $this->globals;
    }

    public function inputFilename(): ?string
    {
        return $this->filename;
    }

    public function libraryPaths(): array
    {
        return $this->libraryPaths;
    }

    public function debug(mixed $value): void
    {
        $this->debugged[] = $value;
    }

    public function writeStderr(mixed $value): void
    {
        $this->stderr[] = $value;
    }

    public function now(): float
    {
        return 0.0;
    }
}
