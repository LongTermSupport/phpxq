<?php

declare(strict_types=1);

namespace LTS\PhpXq\Limits;

/**
 * The deepest nesting phpxq accepts in a jq program or a yq expression: the 10,000 levels jq itself allows.
 * The parsers count every level an AST gains (a bracket, an operator, a postfix step, a nested construct), so
 * the compilers and the evaluators, which walk an AST recursively, never see one deeper than this.
 *
 * @api
 */
final readonly class NestingLimit
{
    public const int MAX_DEPTH = 10000;

    private function __construct()
    {
    }
}
