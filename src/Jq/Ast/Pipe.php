<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left | right`.
 *
 * @api
 */
final readonly class Pipe implements Node
{
    public function __construct(
        public Node $left,
        public Node $right,
    ) {
    }
}
