<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Json\JsonObject;

/**
 * The {@see RuntimeContextInterface} of a program run from PHP code rather than the command line: no
 * input files, no library paths, `debug` and `stderr` output discarded, `$ENV` empty unless the process environment is allowed.
 *
 * @internal
 */
final readonly class EmbeddedContext implements RuntimeContextInterface
{
    /**
     * @param array<string, mixed> $variables the `$name` variables the program can read
     * @param bool                 $allowEnv  whether `$ENV` and `env` show the process environment; empty when not
     */
    public function __construct(
        private array $variables = [],
        private bool $allowEnv = false,
    ) {
    }

    public function inputs(): InputProviderInterface
    {
        return new EmptyInputs();
    }

    public function globals(): array
    {
        $named = JsonObject::fromPairs($this->variables);

        return [
            ...$this->variables,
            'ENV'         => JsonObject::fromPairs($this->allowEnv ? getenv() : []),
            '__prog_args' => $named,
            'ARGS'        => JsonObject::fromPairs(['positional' => [], 'named' => $named]),
        ];
    }

    public function inputFilename(): ?string
    {
        return null;
    }

    public function libraryPaths(): array
    {
        return [];
    }

    public function debug(mixed $value): void
    {
    }

    public function writeStderr(mixed $value): void
    {
    }

    public function now(): float
    {
        return microtime(true);
    }
}
