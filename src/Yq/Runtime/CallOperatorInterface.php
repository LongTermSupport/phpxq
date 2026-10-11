<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\Ast\Call;

/**
 * A named operator or function (`select`, `length`, `style`, `to_entries`...). One implementation may
 * answer to several names (aliases); it is registered once per name returned by names().
 *
 * @internal
 */
interface CallOperatorInterface
{
    /**
     * @return list<string>
     */
    public function names(): array;

    /**
     * @return list<Candidate>
     *
     * @throws EvaluationException
     */
    public function evaluate(Call $call, EvaluationContext $context, EvaluatorInterface $evaluator): array;
}
