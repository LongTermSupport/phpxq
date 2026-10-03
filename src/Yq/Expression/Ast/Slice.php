<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `base[from:to]`; either bound may be absent. Negative bounds count from the end.
 */
final readonly class Slice implements ExpressionNodeInterface
{
    public function __construct(
        public ExpressionNodeInterface $base,
        public ?ExpressionNodeInterface $from,
        public ?ExpressionNodeInterface $to,
        public bool $optional = false,
    ) {
    }
}
