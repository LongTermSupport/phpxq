<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `if c then a else b end`. `elif` chains are nested IfThenElse nodes in $else; a missing `else`
 * leaves $else null and means identity.
 *
 * @api
 */
final readonly class IfThenElse implements NodeInterface
{
    public function __construct(
        public NodeInterface $condition,
        public NodeInterface $then,
        public ?NodeInterface $else,
    ) {
    }
}
