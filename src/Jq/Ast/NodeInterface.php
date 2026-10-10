<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * Marker for every expression node of the jq AST. Nodes are immutable value objects with no behaviour;
 * the compiler dispatches on the concrete class. See CLAUDE/Plan/00003-jq-json-functionality/architecture.md.
 *
 * @internal
 */
interface NodeInterface
{
}
