<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use RuntimeException;

/**
 * A malformed command line (unknown flag, missing flag value). Like cobra, the application prints the
 * usage text after the error message for these.
 *
 * @internal
 */
final class UsageException extends RuntimeException
{
}
