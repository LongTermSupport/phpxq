<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonEncoderInterface;

/**
 * The {@see RuntimeContextInterface} of a command line run: inputs, `$ENV` / `$ARGS` / named arguments, `-L`
 * paths, and `debug` / `stderr` output on the console's standard error.
 *
 * @api
 */
final readonly class CliRuntimeContext implements RuntimeContextInterface
{
    private EncodeOptions $compact;

    /**
     * @param array<string, mixed> $globals
     * @param list<string>         $libraryPaths
     */
    public function __construct(
        private InputSource $input,
        private array $globals,
        private array $libraryPaths,
        private Console $console,
        private JsonEncoderInterface $encoder,
    ) {
        $this->compact = EncodeOptions::compact();
    }

    public function inputs(): InputProviderInterface
    {
        return $this->input;
    }

    public function globals(): array
    {
        return $this->globals;
    }

    public function inputFilename(): ?string
    {
        return $this->input->filename();
    }

    public function libraryPaths(): array
    {
        return $this->libraryPaths;
    }

    public function debug(mixed $value): void
    {
        $this->console->err($this->encoder->encode(['DEBUG:', $value], $this->compact) . "\n");
    }

    public function writeStderr(mixed $value): void
    {
        $this->console->err(\is_string($value) ? $value : $this->encoder->encode($value, $this->compact));
    }

    public function now(): float
    {
        return microtime(true);
    }
}
