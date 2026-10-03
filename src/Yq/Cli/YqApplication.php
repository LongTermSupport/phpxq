<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The yq entry point, wired from the front controller. Owned by the CLI worker (Plan 00004
 * architecture.md, file ownership map). Skeleton only: reports "not implemented".
 */
final class YqApplication implements YqApplicationInterface
{
    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        fwrite($stderr, "yq: not implemented\n");

        return self::EXIT_NOT_IMPLEMENTED;
    }
}
