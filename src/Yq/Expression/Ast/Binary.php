<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `lhs <op> rhs`. For Multiply, `modifiers` holds the characters written after the `*`, in source order
 * (`*+` append arrays, `*?` only existing keys, `*d` deep-merge arrays by index, `*n` no new keys,
 * `*c` clobber custom tags), otherwise it is empty.
 *
 * @internal
 */
final readonly class Binary implements ExpressionNodeInterface
{
    public function __construct(
        public BinaryOperatorEnum $operator,
        public ExpressionNodeInterface $left,
        public ExpressionNodeInterface $right,
        public string $modifiers = '',
    ) {
    }
}
