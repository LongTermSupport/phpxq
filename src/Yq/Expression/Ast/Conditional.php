<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `if c then a elif c2 then b else d end`; `elif` is desugared into a nested Conditional in `otherwise`.
 * A missing `else` is null and means "pass the input through unchanged".
 */
final readonly class Conditional implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $condition,
        public ExpressionNode $then,
        public ?ExpressionNode $otherwise = null,
    ) {
    }
}
