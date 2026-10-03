<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `if c then a else b end`. `elif` chains are nested IfThenElse nodes in $else; a missing `else`
 * leaves $else null and means identity.
 *
 * @api
 */
final readonly class IfThenElse implements Node
{
    public function __construct(
        public Node $condition,
        public Node $then,
        public ?Node $else,
    ) {
    }
}
