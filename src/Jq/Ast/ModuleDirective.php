<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `module {...};` at the head of a library; $metadata is the constant object in the value model.
 *
 * @internal
 */
final readonly class ModuleDirective
{
    public function __construct(public mixed $metadata)
    {
    }
}
