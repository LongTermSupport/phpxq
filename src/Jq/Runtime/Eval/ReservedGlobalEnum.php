<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * The global variables every program has without declaring them: `$ENV` and the named arguments `$__prog_args`.
 *
 * @internal
 */
enum ReservedGlobalEnum: string
{
    case Env = 'ENV';

    case ProgArgs = '__prog_args';
}
