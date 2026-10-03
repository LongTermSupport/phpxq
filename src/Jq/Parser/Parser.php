<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LogicException;
use LTS\PhpXq\Jq\Ast\Program;

/**
 * Parser implementation. OWNER: parser worker. Skeleton only.
 *
 * @api
 */
final readonly class Parser implements ParserInterface
{
    public function __construct(private LexerInterface $lexer)
    {
    }

    public function parse(string $source): Program
    {
        throw new LogicException('Parser is not implemented (lexer: ' . $this->lexer::class . ')');
    }
}
