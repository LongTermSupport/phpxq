<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use RuntimeException;

/**
 * A failure the command line reports as `Error: <message>` with exit status 1.
 */
final class CliException extends RuntimeException
{
}
