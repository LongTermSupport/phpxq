<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Runtime\BuiltinProvider;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;

/**
 * Everything that is not regex or date/time: type, length, keys, paths, math, strings, formats, SQL-style,
 * streaming, input/output, env, and the jq-defined prelude. OWNER: builtins worker; it may add files under
 * src/Jq/Builtin/Core/ and the prelude resource src/Jq/Builtin/prelude.jq. Skeleton registers nothing.
 *
 * @api
 */
final class CoreBuiltins implements BuiltinProvider
{
    public function registerInto(BuiltinRegistry $registry): void
    {
    }
}
