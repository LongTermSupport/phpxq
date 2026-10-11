<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `if c then a elif c2 then b else d end`; `elif` is desugared into a nested Conditional in `otherwise`.
 * A missing `else` is null and means "pass the input through unchanged".
 *
 * @internal
 */
final readonly class Conditional implements ExpressionNodeInterface
{
    public function __construct(
        public ExpressionNodeInterface $condition,
        public ExpressionNodeInterface $then,
        public ?ExpressionNodeInterface $otherwise = null,
    ) {
    }
}
