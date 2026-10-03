<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A numeric literal exactly as written (`1`, `1.5e3`, `.5`, `13911860366432393`). The compiler converts it
 * with the same routine the JSON decoder uses, so that literal-preservation rules are defined once.
 *
 * @api
 */
final readonly class NumberLiteral implements Node
{
    public function __construct(public string $text)
    {
    }
}
