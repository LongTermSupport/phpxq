<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `reduce source as pattern (init; update)`.
 *
 * @internal
 */
final readonly class Reduce implements NodeInterface
{
    public function __construct(
        public NodeInterface $source,
        public PatternInterface $pattern,
        public NodeInterface $init,
        public NodeInterface $update,
    ) {
    }
}
