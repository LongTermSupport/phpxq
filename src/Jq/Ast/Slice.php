<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `target[from:to]`; either bound may be omitted. Path expression capable.
 *
 * @internal
 */
final readonly class Slice implements NodeInterface
{
    public function __construct(
        public NodeInterface $target,
        public ?NodeInterface $from,
        public ?NodeInterface $to,
    ) {
    }
}
