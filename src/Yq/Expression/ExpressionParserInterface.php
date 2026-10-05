<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

/**
 * Parses a yq expression into an AST, applying yq's operator precedence and associativity.
 *
 * An empty expression parses to Ast\Identity. Syntactic sugar is desugared here so the evaluator sees
 * only the closed AST: `elif` becomes nested Conditional, `.a.b` becomes Field(Field(Identity, a), b),
 * `.a | style = "x"` style juxtaposition becomes Binary(Pipe, ...) bound tighter than assignment, and
 * parentheses leave no node.
 *
 * @api
 */
interface ExpressionParserInterface
{
    /**
     * @throws ExpressionSyntaxException
     */
    public function parse(string $expression): ExpressionNodeInterface;
}
