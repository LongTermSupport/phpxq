<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

/**
 * One lexical token with its 1-based source position.
 *
 * $text is documented per {@see TokenType}; for punctuation, operators and keywords it is the source
 * spelling, for Eof it is ''.
 *
 * @api
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $text,
        public int $line,
        public int $column,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }
}
