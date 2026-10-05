<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
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
final readonly class BooleanOperator implements BinaryOperatorInterface
{
    public function operators(): array
    {
        return [BinaryOperatorEnum::And, BinaryOperatorEnum::Or];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $isAnd = BinaryOperatorEnum::And === $expression->operator;

        return Cross::run(
            $expression->left,
            $expression->right,
            $context,
            $evaluator,
            static function (?Candidate $left, ?Candidate $right, ?Candidate $from) use ($isAnd): ?Candidate {
                if (!$left instanceof Candidate || !$right instanceof Candidate) {
                    return null;
                }

                $l = NodeOps::truthy(Cands::node($left));
                $r = NodeOps::truthy(Cands::node($right));

                return Cands::derive(NodeOps::bool($isAnd ? $l && $r : $l || $r), $from);
            },
        );
    }
}
