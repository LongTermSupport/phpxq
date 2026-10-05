<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left | right`.
 *
 * @api
 */
final readonly class Pipe implements NodeInterface
{
    public function __construct(
        public NodeInterface $left,
        public NodeInterface $right,
    ) {
    }
}
