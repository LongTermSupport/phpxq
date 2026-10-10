<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * A string with `\(expr)` interpolation: parts alternate between literal text and expressions whose
 * (string-rendered) result is spliced in.
 *
 * @internal
 */
final readonly class Interpolation implements ExpressionNodeInterface
{
    /**
     * @param list<string|ExpressionNodeInterface> $parts
     */
    public function __construct(
        public array $parts,
    ) {
    }
}
