<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * Process exit codes of the jq command line, as documented in the jq manual.
 *
 * @api
 */
final class JqExitCode
{
    /** all outputs produced; with -e, the last output was neither false nor null */
    public const int OK = 0;

    /** -e given and the last output was false or null */
    public const int LAST_OUTPUT_FALSY = 1;

    /** usage problem or system error (unreadable file, bad option) */
    public const int USAGE = 2;

    /** jq program failed to compile */
    public const int COMPILE_ERROR = 3;

    /** -e given and there was no output at all */
    public const int NO_OUTPUT = 4;

    /** an uncaught runtime error, or invalid JSON on the input */
    public const int RUNTIME_ERROR = 5;

    /** returned while the CLI is still a skeleton (matches FrontControllerInterface::EXIT_NOT_IMPLEMENTED) */
    public const int NOT_IMPLEMENTED = 70;

    private function __construct()
    {
    }
}
