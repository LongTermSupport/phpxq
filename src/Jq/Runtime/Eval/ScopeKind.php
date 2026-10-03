<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * What a compile-time {@see Scope} entry binds.
 *
 * @internal
 */
enum ScopeKind
{
    case Variable;
    case Param;
    case Func;
    case Label;
}
