<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Cross;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * `and` and `or` over the truthiness of the operands (a missing operand is false).
 */
final class BooleanOperator implements BinaryOperatorInterface
{
    public function operators(): array
    {
        return [BinaryOperator::And, BinaryOperator::Or];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $isAnd = BinaryOperator::And === $expression->operator;

        return Cross::run(
            $expression->left,
            $expression->right,
            $context,
            $evaluator,
            static function (?Candidate $left, ?Candidate $right, ?Candidate $from) use ($isAnd): Candidate {
                $l = $left instanceof Candidate && NodeOps::truthy(Cands::node($left));
                $r = $right instanceof Candidate && NodeOps::truthy(Cands::node($right));

                return Cands::derive(NodeOps::bool($isAnd ? $l && $r : $l || $r), $from);
            },
        );
    }
}
