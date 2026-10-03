<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Cross;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * `lhs // rhs`: the truthy results of the left side, or the right side's results when it has none.
 * When the right side is an assignment (`.a // (.a = 0)`), the updated targets are the result, which
 * makes "update or create" work with the compound assignments.
 */
final class AlternativeOperator implements BinaryOperatorInterface
{
    private const array ASSIGNMENTS = [
        BinaryOperatorEnum::Assign,
        BinaryOperatorEnum::Update,
        BinaryOperatorEnum::AddAssign,
        BinaryOperatorEnum::SubtractAssign,
        BinaryOperatorEnum::MultiplyAssign,
        BinaryOperatorEnum::DivideAssign,
        BinaryOperatorEnum::ModuloAssign,
    ];

    public function operators(): array
    {
        return [BinaryOperatorEnum::Alternative];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $read = $context->withDontAutoCreate(true);
        $out  = [];
        foreach (Cross::units($context) as $unit) {
            $lefts = [];
            foreach ($evaluator->evaluate($expression->left, $read->withMatches($unit)) as $candidate) {
                if (NodeOps::truthy(Cands::node($candidate))) {
                    $lefts[] = $candidate;
                }
            }

            if ([] !== $lefts) {
                foreach ($lefts as $candidate) {
                    $out[] = $candidate;
                }

                continue;
            }

            $sub = $context->withMatches($unit);
            if ($expression->right instanceof Binary && \in_array($expression->right->operator, self::ASSIGNMENTS, true)) {
                $evaluator->evaluate($expression->right, $sub);
                foreach ($evaluator->evaluate($expression->right->left, $sub) as $candidate) {
                    $out[] = $candidate;
                }

                continue;
            }

            foreach ($evaluator->evaluate($expression->right, $sub) as $candidate) {
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
