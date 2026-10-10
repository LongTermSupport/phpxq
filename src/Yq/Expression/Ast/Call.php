<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * A named operator or function: `select(.a)`, `length`, `keys`, `not`, `with(.a; . = 1)`, `has("k")`,
 * `to_entries`, `style`, `line_comment`, `env(NAME)`, `@`-free. Arguments are separated by `;` in the
 * source. A bare word with no parentheses is a Call with no arguments. The evaluator looks `name` up in
 * the operator registry, so adding an operator never touches the AST or the parser.
 *
 * @internal
 */
final readonly class Call implements ExpressionNodeInterface
{
    /**
     * @param list<ExpressionNodeInterface> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
    ) {
    }
}
