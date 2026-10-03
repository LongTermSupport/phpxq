<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;

/**
 * A plain {@see RuntimeContextInterface} for tests.
 */
final readonly class StubContext implements RuntimeContextInterface
{
    /**
     * @param array<string, mixed> $globals
     * @param list<string>         $libraryPaths
     */
    public function __construct(
        private array $globals = [],
        private array $libraryPaths = [],
    ) {
    }

    public function inputs(): InputProviderInterface
    {
        return new class implements InputProviderInterface {
            public function hasNext(): bool
            {
                return false;
            }

            public function next(): mixed
            {
                throw new JqException('No more inputs');
            }
        };
    }

    public function globals(): array
    {
        return $this->globals;
    }

    public function inputFilename(): ?string
    {
        return null;
    }

    public function libraryPaths(): array
    {
        return $this->libraryPaths;
    }

    public function debug(mixed $value): void
    {
    }

    public function writeStderr(mixed $value): void
    {
    }

    public function now(): float
    {
        return 0.0;
    }
}
