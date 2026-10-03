<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `label $name | body`; a {@see BreakOut} naming the same label inside $body ends the body's output.
 *
 * @api
 */
final readonly class Label implements Node
{
    public function __construct(
        public string $name,
        public Node $body,
    ) {
    }
}
