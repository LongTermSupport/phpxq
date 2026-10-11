<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * One `key: value` pair of a `{ ... }` constructor. The shorthand `{a}` is `{a: .a}` and is desugared by
 * the parser.
 *
 * @internal
 */
final readonly class ObjectEntry
{
    public function __construct(
        public ExpressionNodeInterface $key,
        public ExpressionNodeInterface $value,
    ) {
    }
}
