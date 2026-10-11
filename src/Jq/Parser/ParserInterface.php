<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Runtime\JqCompileException;

/**
 * jq source text to AST. Implementations take a {@see LexerInterface} in the constructor.
 *
 * Operator precedence, lowest to highest, as in jq's grammar: `|` (right assoc), `,`, `//` (right assoc),
 * `=` `|=` `+=` `-=` `*=` `/=` `%=` `//=` (non-assoc), `or`, `and`, comparison (non-assoc), `+` `-`,
 * `*` `/` `%`, unary minus, postfix (`?`, `.foo`, `[...]`, `as` bindings are handled as a pipe-level form).
 * `def`, `reduce`, `foreach`, `if`, `try`, `label` and `source as $x | body` follow jq's grammar exactly.
 * Desugaring the parser performs is listed on each node class (object shorthand, `..`, `elif`, `?`).
 *
 * @internal
 */
interface ParserInterface
{
    /**
     * Parse a main program or a library module.
     *
     * @throws JqCompileException syntax error, phrased as jq phrases it
     */
    public function parse(string $source): Program;
}
