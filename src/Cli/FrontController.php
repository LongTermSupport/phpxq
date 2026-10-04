<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

use Throwable;

/**
 * @api
 */
final class FrontController implements FrontControllerInterface
{
    public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        $tool = [] === $args ? null : ToolEnum::tryFrom($args[0]);
        if (null === $tool) {
            fwrite($stderr, ToolEnum::usage());

            return self::EXIT_USAGE;
        }

        try {
            return $tool->run($stdin, $stdout, $stderr, ...\array_slice($args, 1));
        } catch (Throwable $throwable) {
            fwrite($stderr, \sprintf("%s: error (at <unknown>): internal error: %s\n", $tool->value, $throwable->getMessage()));

            return self::EXIT_INTERNAL;
        }
    }
}
