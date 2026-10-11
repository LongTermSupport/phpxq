<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * Interprets an expression AST. Structural nodes (Identity, Field, Index-style traversal, Slice,
 * Iterate, RecursiveDescent, Literal, VariableRef, Collect, ObjectConstruct, Conditional, Bind, Reduce,
 * Interpolation) are handled by the evaluator itself; Call and Binary dispatch to the operator registry.
 *
 * @internal
 */
interface EvaluatorInterface
{
    /**
     * @return list<Candidate> the matches of `expression` against `context->matches`
     *
     * @throws EvaluationException
     */
    public function evaluate(ExpressionNodeInterface $expression, EvaluationContext $context): array;
}
