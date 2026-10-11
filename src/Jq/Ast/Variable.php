<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `$name` (name without the `$`). `$ENV`, `$__prog_args` and named arguments are ordinary variables
 * bound by the runtime context; `$__loc__` is {@see Location}.
 *
 * @internal
 */
final readonly class Variable implements NodeInterface
{
    public function __construct(
        public string $name,
        public int $line = 1,
    ) {
    }
}
