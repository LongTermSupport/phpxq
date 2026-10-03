<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * A number, string, `true`, `false` or `null` literal, already as a scalar Node (tag !!int, !!float,
 * !!str, !!bool or !!null). The evaluator deep-copies it before returning it.
 */
final readonly class Literal implements ExpressionNode
{
    public function __construct(
        public Node $value,
    ) {
    }
}
