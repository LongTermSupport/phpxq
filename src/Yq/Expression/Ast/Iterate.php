<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `base[]` and `base.[]`: every value of a mapping or every item of a sequence.
 */
final readonly class Iterate implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $base,
        public bool $optional = false,
    ) {
    }
}
