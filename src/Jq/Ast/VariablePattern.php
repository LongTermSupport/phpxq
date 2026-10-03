<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `$name` as a pattern (name without the `$`).
 *
 * @api
 */
final readonly class VariablePattern implements PatternInterface
{
    public function __construct(public string $name)
    {
    }
}
