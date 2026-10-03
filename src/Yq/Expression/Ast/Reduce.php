<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `reduce source as $name (initial; update)`.
 */
final readonly class Reduce implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $source,
        public string $name,
        public ExpressionNode $initial,
        public ExpressionNode $update,
    ) {
    }
}
