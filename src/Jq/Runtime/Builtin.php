<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * A native (PHP implemented) builtin, identified by name and arity. Either a {@see ValueBuiltin}
 * (every argument is a value) or a {@see StreamBuiltin} (arguments are closure parameters).
 *
 * @api
 */
interface Builtin
{
    public function name(): string;

    /**
     * Number of arguments, so `ltrimstr/1` is name "ltrimstr", arity 1.
     */
    public function arity(): int;
}
