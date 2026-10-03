<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `base[from:to]`; either bound may be absent. Negative bounds count from the end.
 */
final readonly class Slice implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $base,
        public ?ExpressionNode $from,
        public ?ExpressionNode $to,
        public bool $optional = false,
    ) {
    }
}
