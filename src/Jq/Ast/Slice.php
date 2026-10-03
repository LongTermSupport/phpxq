<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `target[from:to]`; either bound may be omitted. Path expression capable.
 *
 * @api
 */
final readonly class Slice implements Node
{
    public function __construct(
        public Node $target,
        public ?Node $from,
        public ?Node $to,
    ) {
    }
}
