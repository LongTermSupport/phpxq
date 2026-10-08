<?php

declare(strict_types=1);

namespace LTS\PhpXq\Limits;

/**
 * The deepest nesting phpxq accepts in a jq program or a yq expression. MAX_DEPTH is the 10,000 levels jq
 * itself allows, counted over what the parsers recurse into (brackets, right-recursive operands, nested
 * constructs). MAX_TREE_DEPTH bounds the whole tree, chains included: a chain such as `.a.a.a` or `1+1+1` is
 * built in a loop but is as deep as it is long, and PHP frees a tree recursively on the native stack, which
 * overflowed near 700,000 levels on an 8 MiB stack. 100,000 keeps a wide margin for smaller stacks.
 *
 * @api
 */
final readonly class NestingLimit
{
    public const int MAX_DEPTH = 10000;

    public const int MAX_TREE_DEPTH = 100000;

    private function __construct()
    {
    }
}
