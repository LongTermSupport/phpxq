<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support;

use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;

/**
 * A recording {@see RuntimeContextInterface} for builtin tests.
 *
 * @internal
 */
final class FakeContext implements RuntimeContextInterface
{
    /** @var list<mixed> */
    public array $debugged = [];

    /** @var list<mixed> */
    public array $stderr = [];

    /**
     * @param list<mixed>          $inputs
     * @param array<string, mixed> $globals
     * @param list<string>         $libraryPaths
     * @param ?int                 $line         the line `input_line_number` reports; null for 0
     */
    public function __construct(
        private readonly array $inputs = [],
        private readonly array $globals = [],
        private readonly ?string $filename = null,
        private readonly array $libraryPaths = [],
        private readonly ?int $line = null,
    ) {
    }

    public function inputs(): InputProviderInterface
    {
        return new FakeInputs($this->inputs, $this->line ?? 0);
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
