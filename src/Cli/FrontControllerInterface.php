<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

/**
 * The single entry point for every phpxq command line invocation.
 *
 * The first argument names the tool to run (`jq` or `yq`); everything after it is that tool's own
 * command line, exactly as jq or yq would receive it. Streams are injected so a front controller can be
 * driven in-process by the conformance suites as well as by `bin/phpxq`.
 *
 * @api
 */
interface FrontControllerInterface
{
    public const int EXIT_OK              = 0;

    public const int EXIT_USAGE           = 2;

    public const int EXIT_INTERNAL        = 5;

    public const int EXIT_NOT_IMPLEMENTED = 70;

    /**
     * @param list<string> $args   arguments after the program name; the first one is the tool name
     * @param resource     $stdin
     * @param resource     $stdout
     * @param resource     $stderr
     *
     * @return int the process exit code
     */
    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int;
}
