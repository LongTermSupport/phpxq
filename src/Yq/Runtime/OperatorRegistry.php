<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LogicException;
use LTS\PhpXq\Yq\Expression\Ast\BinaryOperator;

/**
 * Owned by the evaluator/operators worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class OperatorRegistry implements OperatorRegistryInterface
{
    public function call(string $name): ?CallOperatorInterface
    {
        throw new LogicException('OperatorRegistry is not implemented');
    }

    public function binary(BinaryOperator $operator): ?BinaryOperatorInterface
    {
        throw new LogicException('OperatorRegistry is not implemented');
    }

    public function registerCall(CallOperatorInterface $operator): void
    {
        throw new LogicException('OperatorRegistry is not implemented');
    }

    public function registerBinary(BinaryOperatorInterface $operator): void
    {
        throw new LogicException('OperatorRegistry is not implemented');
    }
}
