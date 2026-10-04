<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

use Throwable;

/**
 * @api
 */
final class FrontController implements FrontControllerInterface
{
    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        $tool = ToolEnum::tryFrom($args[0] ?? '');
        if (null === $tool) {
            fwrite($stderr, ToolEnum::usage());

            return self::EXIT_USAGE;
        }

        try {
            return $tool->run(\array_slice($args, 1), $stdin, $stdout, $stderr);
        } catch (Throwable $throwable) {
            fwrite($stderr, \sprintf("%s: error (at <unknown>): internal error: %s\n", $tool->value, $throwable->getMessage()));

            return self::EXIT_INTERNAL;
        }
    }
}
