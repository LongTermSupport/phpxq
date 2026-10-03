<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `.` : the current matches, unchanged.
 */
final readonly class Identity implements ExpressionNodeInterface
{
}
