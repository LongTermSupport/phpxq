<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * `source as $name | body` (and the `ref` spelling): binds every match of `source` to `$name` in turn
 * and evaluates `body` against the original input once per binding. `body` extends as far right as the
 * enclosing pipe allows.
 *
 * `reference` is true for the `ref` spelling: the variable aliases the matched nodes (so `$x = "new"`
 * updates the document) instead of holding copies, as `as` does.
 */
final readonly class Bind implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $source,
        public string $name,
        public ExpressionNode $body,
        public bool $reference = false,
    ) {
    }
}
