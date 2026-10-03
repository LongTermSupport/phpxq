<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LogicException;

/**
 * Lexer implementation. OWNER: lexer worker. Skeleton only.
 *
 * @api
 */
final class Lexer implements LexerInterface
{
    public function tokenize(string $source): array
    {
        throw new LogicException('Lexer is not implemented');
    }
}
