<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

use LogicException;

/**
 * Owned by the expression lexer/parser worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class ExpressionLexer implements ExpressionLexerInterface
{
    public function tokenize(string $expression): array
    {
        throw new LogicException('ExpressionLexer is not implemented');
    }
}
