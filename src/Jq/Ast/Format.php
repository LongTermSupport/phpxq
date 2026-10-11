<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A bare `@name` applied to the input, for example `@base64` or `@csv`. $name has no `@`.
 *
 * @internal
 */
final readonly class Format implements NodeInterface
{
    public function __construct(public string $name)
    {
    }
}
