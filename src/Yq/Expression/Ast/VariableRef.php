<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `$name`. `$ENV` and `$__loc__`-style built-ins are ordinary names resolved by the evaluator.
 *
 * @internal
 */
final readonly class VariableRef implements ExpressionNodeInterface
{
    public function __construct(
        public string $name,
    ) {
    }
}
