<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `lhs <op> rhs`. For Multiply, `modifiers` holds the characters written after the `*`, in source order
 * (`*+` append arrays, `*?` only existing keys, `*d` deep-merge arrays by index, `*n` no new keys,
 * `*c` clobber custom tags), otherwise it is empty.
 */
final readonly class Binary implements ExpressionNode
{
    public function __construct(
        public BinaryOperator $operator,
        public ExpressionNode $left,
        public ExpressionNode $right,
        public string $modifiers = '',
    ) {
    }
}
