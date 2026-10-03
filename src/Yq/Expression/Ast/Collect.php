<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `[ expr ]`: all matches of `inner` gathered into one sequence; `[]` has no inner expression.
 */
final readonly class Collect implements ExpressionNode
{
    public function __construct(
        public ?ExpressionNode $inner = null,
    ) {
    }
}
