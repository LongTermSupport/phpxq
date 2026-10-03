<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * A malformed command line (unknown flag, missing flag value). Like cobra, the application prints the
 * usage text after the error message for these.
 */
final class UsageException extends CliException
{
}
