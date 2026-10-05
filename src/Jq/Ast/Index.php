<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `target[index]`, which is also what `.foo`, `."foo"` and `.["foo"]` parse to (target Identity unless
 * chained, index a string Literal). Path expression capable.
 *
 * @api
 */
final readonly class Index implements NodeInterface
{
    public function __construct(
        public NodeInterface $target,
        public NodeInterface $index,
    ) {
    }
}
