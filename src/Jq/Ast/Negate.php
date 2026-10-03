<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * Unary minus: `-expr`.
 *
 * @api
 */
final readonly class Negate implements Node
{
    public function __construct(public Node $operand)
    {
    }
}
