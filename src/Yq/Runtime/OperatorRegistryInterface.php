<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yq\Expression\Ast\BinaryOperatorEnum;

/**
 * The lookup table the evaluator uses for Call and Binary nodes. The evaluator worker owns every
 * implementation; operators live under Yq\Runtime\Operators, one class per operator or family, and are
 * registered by OperatorRegistry's constructor.
 *
 * @internal
 */
interface OperatorRegistryInterface
{
    public function call(string $name): ?CallOperatorInterface;

    public function binary(BinaryOperatorEnum $operator): ?BinaryOperatorInterface;
}
