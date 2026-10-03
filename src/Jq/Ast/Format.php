<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A bare `@name` applied to the input, for example `@base64` or `@csv`. $name has no `@`.
 *
 * @api
 */
final readonly class Format implements Node
{
    public function __construct(public string $name)
    {
    }
}
