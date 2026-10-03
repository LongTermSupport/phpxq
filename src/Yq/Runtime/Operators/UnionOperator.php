<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;

/**
 * `lhs, rhs`: both sides against the same matches, results concatenated.
 */
final class UnionOperator implements BinaryOperatorInterface
{
    public function operators(): array
    {
        return [BinaryOperator::Union];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        return [...$evaluator->evaluate($expression->left, $context), ...$evaluator->evaluate($expression->right, $context)];
    }
}
