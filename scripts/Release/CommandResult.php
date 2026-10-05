<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

/**
 * What a subcommand produced: `$stdout` is the result a caller captures, `$stderr` is for a person.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {
    }
}
