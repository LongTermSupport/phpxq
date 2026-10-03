<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNode;

/**
 * One `key: value` pair of a `{ ... }` constructor. The shorthand `{a}` is `{a: .a}` and is desugared by
 * the parser.
 */
final readonly class ObjectEntry
{
    public function __construct(
        public ExpressionNode $key,
        public ExpressionNode $value,
    ) {
    }
}
