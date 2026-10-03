<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

use LogicException;

/**
 * Owned by the expression lexer/parser worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class ExpressionParser implements ExpressionParserInterface
{
    public function parse(string $expression): ExpressionNode
    {
        throw new LogicException('ExpressionParser is not implemented');
    }
}
