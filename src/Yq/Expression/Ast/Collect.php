<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `[ expr ]`: all matches of `inner` gathered into one sequence; `[]` has no inner expression.
 *
 * @internal
 */
final readonly class Collect implements ExpressionNodeInterface
{
    public function __construct(
        public ?ExpressionNodeInterface $inner = null,
    ) {
    }
}
