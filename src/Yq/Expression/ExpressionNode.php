<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

/**
 * Marker for every node of the yq expression AST (see Yq\Expression\Ast). The AST is a closed set of
 * immutable value classes; the parser builds it and the evaluator interprets it.
 *
 * @api
 */
interface ExpressionNode
{
}
