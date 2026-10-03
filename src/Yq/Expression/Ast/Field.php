<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `base.name`, `base."quoted name"`, `base.["name"]` (as a Literal key) and the glob forms such as
 * `base.a*`: a traversal into a mapping by key, or into a sequence by integer key.
 *
 * `key` is evaluated against the original input, not against `base` (as in yq's `.a[.b]`). A glob in
 * a plain name is recognised by the evaluator from the key text, not by a separate node.
 * `optional` marks a trailing `?`, which turns a traversal error into no match.
 */
final readonly class Field implements ExpressionNodeInterface
{
    public function __construct(
        public ExpressionNodeInterface $base,
        public ExpressionNodeInterface $key,
        public bool $optional = false,
    ) {
    }
}
