<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `target[]`. Path expression capable.
 *
 * @api
 */
final readonly class Iterate implements NodeInterface
{
    public function __construct(public NodeInterface $target)
    {
    }
}
