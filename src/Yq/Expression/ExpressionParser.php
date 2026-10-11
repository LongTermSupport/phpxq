<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

use LTS\PhpXq\Yq\Expression\Parser\PrattParser;

/**
 * Lexes and parses a yq expression. The grammar, operator precedence and juxtaposition rule live in
 * Parser\PrattParser; this class only wires the lexer to it.
 *
 * @internal
 */
final readonly class ExpressionParser implements ExpressionParserInterface
{
    private ExpressionLexerInterface $lexer;

    public function __construct(?ExpressionLexerInterface $lexer = null)
    {
        $this->lexer = $lexer ?? new ExpressionLexer();
    }

    public function parse(string $expression): ExpressionNodeInterface
    {
        return new PrattParser($this->lexer->tokenize($expression))->parseAll();
    }
}
