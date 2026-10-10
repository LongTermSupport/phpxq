<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `left op right` where op is an assignment operator; $left is a path expression.
 *
 * @internal
 */
final readonly class Assign implements NodeInterface
{
    public function __construct(
        public AssignOpEnum $op,
        public NodeInterface $left,
        public NodeInterface $right,
    ) {
    }
}
