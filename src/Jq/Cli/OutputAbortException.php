<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use RuntimeException;

/**
 * Raised by the output step to end the program run for the current input (a string with a NUL under
 * `--raw-output0`, or a closed stdout). It is deliberately not a {@see \LTS\PhpXq\Jq\Runtime\JqException},
 * so no `try` in the jq program can catch it.
 *
 * @api
 */
final class OutputAbortException extends RuntimeException
{
}
