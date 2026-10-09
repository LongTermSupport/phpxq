<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The yq command line: everything after `phpxq yq` is this application's argv.
 *
 * Exit codes follow the reference: 0 success, 1 any runtime or usage error (message on stderr as
 * `Error: ...`). EXIT_NOT_IMPLEMENTED (70) is only used by the skeleton.
 *
 * @internal
 */
interface YqApplicationInterface
{
    public const int EXIT_OK              = 0;

    public const int EXIT_ERROR           = 1;

    public const int EXIT_NOT_IMPLEMENTED = 70;

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     * @param string   ...$args arguments after `yq`
     */
    public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int;
}
