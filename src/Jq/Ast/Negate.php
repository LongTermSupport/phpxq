<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * Unary minus: `-expr`.
 *
 * @api
 */
final readonly class Negate implements NodeInterface
{
    public function __construct(public NodeInterface $operand)
    {
    }
}
