<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `base..` (values only) and `base...` (keys as well, `includeKeys` true).
 */
final readonly class RecursiveDescent implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $base,
        public bool $includeKeys = false,
    ) {
    }
}
