<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Runtime\BuiltinProvider;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;

/**
 * Regex builtins (match, test, capture, scan, split/2, splits, sub, gsub, ascii-escaping helpers) and the
 * Oniguruma to PCRE translation layer. OWNER: regex/date worker; it may add files under
 * src/Jq/Builtin/Regex/. Skeleton registers nothing.
 *
 * @api
 */
final class RegexBuiltins implements BuiltinProvider
{
    public function registerInto(BuiltinRegistry $registry): void
    {
    }
}
