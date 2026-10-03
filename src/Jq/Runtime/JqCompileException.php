<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use RuntimeException;

/**
 * A lexing, parsing or compile-time error (undefined function or variable, bad import, unknown label).
 * The message is the jq wording without the leading `jq: error: ` and without the trailing
 * `jq: 1 compile error` line; the CLI adds both and exits with status 3.
 *
 * @api
 */
final class JqCompileException extends RuntimeException
{
}
