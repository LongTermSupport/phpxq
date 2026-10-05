<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `base..` (values only) and `base...` (keys as well, `includeKeys` true).
 */
final readonly class RecursiveDescent implements ExpressionNodeInterface
{
    public function __construct(
        public ExpressionNodeInterface $base,
        public bool $includeKeys = false,
    ) {
    }
}
