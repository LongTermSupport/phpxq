<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Runtime\BuiltinProvider;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;

/**
 * Date/time builtins (mktime, gmtime, localtime, strftime, strflocaltime, strptime, todate, fromdate,
 * dateadd, ...). OWNER: regex/date worker; it may add files under src/Jq/Builtin/Date/. Skeleton registers
 * nothing.
 *
 * @api
 */
final class DateBuiltins implements BuiltinProvider
{
    public function registerInto(BuiltinRegistry $registry): void
    {
    }
}
