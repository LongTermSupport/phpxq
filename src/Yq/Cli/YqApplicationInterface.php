<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The yq command line: everything after `phpxq yq` is this application's argv.
 *
 * Exit codes follow the reference: 0 success, 1 any runtime or usage error (message on stderr as
 * `Error: ...`).
 *
 * @internal
 */
interface YqApplicationInterface
{
    public const int EXIT_OK              = 0;

    public const int EXIT_ERROR           = 1;
}
