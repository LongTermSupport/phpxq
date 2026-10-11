<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `foreach source as pattern (init; update)` or with a trailing `; extract`. (`Foreach` is a reserved
 * word in PHP, hence the name.).
 *
 * @internal
 */
final readonly class ForeachLoop implements NodeInterface
{
    public function __construct(
        public NodeInterface $source,
        public PatternInterface $pattern,
        public NodeInterface $init,
        public NodeInterface $update,
        public ?NodeInterface $extract,
    ) {
    }
}
