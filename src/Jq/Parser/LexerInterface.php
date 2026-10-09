<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LTS\PhpXq\Jq\Runtime\JqCompileException;

/**
 * jq source text to tokens.
 *
 * Whitespace and comments are skipped (a `#` comment runs to end of line; jq 1.8 lets an odd number of
 * backslashes before the newline continue the comment on the next line). The returned list always ends
 * with exactly one Eof token. Lexical errors (unterminated string, bad escape, unknown character) throw
 * {@see JqCompileException} with jq's wording.
 *
 * @internal
 */
interface LexerInterface
{
    /**
     * @return non-empty-list<Token>
     *
     * @throws JqCompileException
     */
    public function tokenize(string $source): array;
}
