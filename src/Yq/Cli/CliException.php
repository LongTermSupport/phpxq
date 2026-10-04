<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use RuntimeException;

/**
 * A failure the command line reports as `Error: <message>` with exit status 1.
 */
final class CliException extends RuntimeException
{
    /** Code of the failure when the output sink went away (a closed pipe): the run ends quietly, as a SIGPIPE death would. */
    public const int BROKEN_PIPE = 32;

    /** Exit status of a process killed by SIGPIPE. */
    public const int BROKEN_PIPE_EXIT = 141;
}
