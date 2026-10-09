<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `label $name | body`; a {@see BreakOut} naming the same label inside $body ends the body's output.
 *
 * @internal
 */
final readonly class Label implements NodeInterface
{
    public function __construct(
        public string $name,
        public NodeInterface $body,
    ) {
    }
}
