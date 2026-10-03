<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\Ast\Binary;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;

/**
 * An infix operator: pipe, union, the assignment family, alternative, boolean, comparison, arithmetic.
 *
 * @api
 */
interface BinaryOperatorInterface
{
    /**
     * @return list<BinaryOperator> every operator this class implements
     */
    public function operators(): array;

    /**
     * @return list<Candidate>
     *
     * @throws EvaluationException
     */
    public function evaluate(Binary $expression, EvaluationContext $context, EvaluatorInterface $evaluator): array;
}
