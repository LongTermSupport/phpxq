<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

/**
 * Splits a yq expression into tokens. Whitespace and `#` comments are skipped.
 *
 * @api
 */
interface ExpressionLexerInterface
{
    /**
     * @return list<ExpressionToken> always ends with an EndOfInput token
     *
     * @throws ExpressionSyntaxException
     */
    public function tokenize(string $expression): array;
}
