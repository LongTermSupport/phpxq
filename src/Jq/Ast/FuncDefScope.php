<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `def f: ...; rest`: a nested definition visible in $rest (and recursively in its own body).
 *
 * @api
 */
final readonly class FuncDefScope implements Node
{
    public function __construct(
        public FuncDef $def,
        public Node $rest,
    ) {
    }
}
