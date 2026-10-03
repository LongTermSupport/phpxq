<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `target[]`. Path expression capable.
 *
 * @api
 */
final readonly class Iterate implements Node
{
    public function __construct(public Node $target)
    {
    }
}
