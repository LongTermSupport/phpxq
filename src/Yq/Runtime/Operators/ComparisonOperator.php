<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;
use LTS\PhpXq\Yq\Runtime\BinaryOperatorInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\Cands;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\Cross;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\NodeOps;

/**
 * `==`, `!=`, `<`, `<=`, `>`, `>=`. A side with no match counts as null, so two missing keys are equal.
 */
final readonly class ComparisonOperator implements BinaryOperatorInterface
{
    public function operators(): array
    {
        return [
            BinaryOperatorEnum::Equal,
            BinaryOperatorEnum::NotEqual,
            BinaryOperatorEnum::Less,
            BinaryOperatorEnum::LessOrEqual,
            BinaryOperatorEnum::Greater,
            BinaryOperatorEnum::GreaterOrEqual,
        ];
    }

    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array
    {
        $operator = $expression->operator;
        $layout   = Cands::dateLayout($context);

        return Cross::run(
            $expression->left,
            $expression->right,
            $context,
            $evaluator,
            static function (?Candidate $left, ?Candidate $right, ?Candidate $from) use ($operator, $layout): Candidate {
                $l = $left instanceof Candidate ? Cands::node($left) : NodeOps::null();
                $r = $right instanceof Candidate ? Cands::node($right) : NodeOps::null();

                return Cands::deriveInDocument(NodeOps::bool(self::decide($operator, $l, $r, $layout)), $from);
            },
        );
    }

    private static function decide(BinaryOperatorEnum $operator, Node $left, Node $right, ?string $layout): bool
    {
        switch ($operator) {
            case BinaryOperatorEnum::Equal:
                return Compare::equals($left, $right);

            case BinaryOperatorEnum::NotEqual:
                return !Compare::equals($left, $right);

            default:
                $order = Compare::order($left, $right, $layout);

                return match ($operator) {
                    BinaryOperatorEnum::Less           => $order < 0,
                    BinaryOperatorEnum::LessOrEqual    => $order <= 0,
                    BinaryOperatorEnum::Greater        => $order > 0,
                    default                            => $order >= 0,
                };
        }
    }
}
