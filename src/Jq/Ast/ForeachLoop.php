<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `foreach source as pattern (init; update)` or with a trailing `; extract`. (`Foreach` is a reserved
 * word in PHP, hence the name.).
 *
 * @api
 */
final readonly class ForeachLoop implements Node
{
    public function __construct(
        public Node $source,
        public Pattern $pattern,
        public Node $init,
        public Node $update,
        public ?Node $extract,
    ) {
    }
}
