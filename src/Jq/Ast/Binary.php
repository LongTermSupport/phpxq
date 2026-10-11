<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left op right`. Evaluation order follows jq: for arithmetic and comparison the right operand is the
 * outer loop and the left the inner one; `and`, `or` and `//` short-circuit.
 *
 * @internal
 */
final readonly class Binary implements NodeInterface
{
    public function __construct(
        public BinaryOpEnum $op,
        public NodeInterface $left,
        public NodeInterface $right,
    ) {
    }
}
