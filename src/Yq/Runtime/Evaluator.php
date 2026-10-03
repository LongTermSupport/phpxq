<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LogicException;
use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * Owned by the evaluator/operators worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final readonly class Evaluator implements EvaluatorInterface
{
    public function __construct(
        private OperatorRegistryInterface $operators = new OperatorRegistry(),
    ) {
    }

    public function evaluate(ExpressionNode $expression, EvaluationContext $context): array
    {
        throw new LogicException('Evaluator is not implemented (' . $this->operators::class . ')');
    }
}
