<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `.` : the current matches, unchanged.
 */
final readonly class Identity implements ExpressionNode
{
}
