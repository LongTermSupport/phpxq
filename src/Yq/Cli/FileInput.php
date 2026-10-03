<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * One input to read: a file name (`-` for standard input), and optionally its content when the caller
 * already holds it (the YAML part of a front matter file).
 */
final readonly class FileInput
{
    public function __construct(
        public string $name,
        public ?string $content = null,
    ) {
    }
}
