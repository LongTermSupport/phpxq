<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left op right` where op is an assignment operator; $left is a path expression.
 *
 * @api
 */
final readonly class Assign implements Node
{
    public function __construct(
        public AssignOp $op,
        public Node $left,
        public Node $right,
    ) {
    }
}
