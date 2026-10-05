<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `base[]` and `base.[]`: every value of a mapping or every item of a sequence.
 */
final readonly class Iterate implements ExpressionNodeInterface
{
    public function __construct(
        public ExpressionNodeInterface $base,
    ) {
    }
}
