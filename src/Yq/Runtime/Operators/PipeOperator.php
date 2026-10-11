<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;

/**
 * `lhs | rhs`: the right side runs against the left side's matches.
 *
 * @internal
 */
final readonly class PipeOperator implements BinaryOperatorInterface
{
    public function operators(): array
    {
        return [BinaryOperatorEnum::Pipe];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        return $evaluator->evaluate($expression->right, $context->withMatches(...$evaluator->evaluate($expression->left, $context)));
    }
}
