<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left, right`: all outputs of left, then all outputs of right, on the same input.
 *
 * @api
 */
final readonly class Comma implements Node
{
    public function __construct(
        public Node $left,
        public Node $right,
    ) {
    }
}
