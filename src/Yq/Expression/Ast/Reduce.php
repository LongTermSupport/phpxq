<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `reduce source as $name (initial; update)`.
 *
 * @internal
 */
final readonly class Reduce implements ExpressionNodeInterface
{
    public function __construct(
        public ExpressionNodeInterface $source,
        public string $name,
        public ExpressionNodeInterface $initial,
        public ExpressionNodeInterface $update,
    ) {
    }
}
