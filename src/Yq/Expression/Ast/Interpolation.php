<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * A string with `\(expr)` interpolation: parts alternate between literal text and expressions whose
 * (string-rendered) result is spliced in.
 */
final readonly class Interpolation implements ExpressionNode
{
    /**
     * @param list<string|ExpressionNode> $parts
     */
    public function __construct(
        public array $parts,
    ) {
    }
}
