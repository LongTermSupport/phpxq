<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Yq\Cli\YqApplication;
use Throwable;

/**
 * @api
 */
final class FrontController implements FrontControllerInterface
{
    private const array TOOLS = ['jq', 'yq'];

    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        $tool = $args[0] ?? '';
        if (!\in_array($tool, self::TOOLS, true)) {
            fwrite($stderr, "usage: phpxq jq|yq [arguments...]\n");

            return self::EXIT_USAGE;
        }

        try {
            if ('jq' === $tool) {
                return JqApplication::create()->run(\array_slice($args, 1), $stdin, $stdout, $stderr);
            }

            return new YqApplication()->run(\array_slice($args, 1), $stdin, $stdout, $stderr);
        } catch (Throwable $throwable) {
            fwrite($stderr, \sprintf("%s: error (at <unknown>): internal error: %s\n", $tool, $throwable->getMessage()));

            return self::EXIT_INTERNAL;
        }
    }
}
