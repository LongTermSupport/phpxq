<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left op right`. Evaluation order follows jq: for arithmetic and comparison the right operand is the
 * outer loop and the left the inner one; `and`, `or` and `//` short-circuit.
 *
 * @api
 */
final readonly class Binary implements Node
{
    public function __construct(
        public BinaryOp $op,
        public Node $left,
        public Node $right,
    ) {
    }
}
