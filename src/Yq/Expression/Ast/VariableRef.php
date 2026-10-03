<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `$name`. `$ENV` and `$__loc__`-style built-ins are ordinary names resolved by the evaluator.
 */
final readonly class VariableRef implements ExpressionNode
{
    public function __construct(
        public string $name,
    ) {
    }
}
