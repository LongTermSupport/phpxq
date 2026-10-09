<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

/**
 * One lexical token with its 1-based source position.
 *
 * $text is documented per {@see TokenTypeEnum}; for punctuation, operators and keywords it is the source
 * spelling, for Eof it is ''.
 *
 * @internal
 */
final readonly class Token
{
    public function __construct(
        public TokenTypeEnum $type,
        public string $text,
        public int $line,
        public int $column,
    ) {
    }
}
