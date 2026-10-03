<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `reduce source as pattern (init; update)`.
 *
 * @api
 */
final readonly class Reduce implements Node
{
    public function __construct(
        public Node $source,
        public Pattern $pattern,
        public Node $init,
        public Node $update,
    ) {
    }
}
