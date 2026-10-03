<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Yq\Cli\YqApplication;

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

        if ('jq' === $tool) {
            return JqApplication::create()->run(\array_slice($args, 1), $stdin, $stdout, $stderr);
        }

        if ('yq' === $tool) {
            return new YqApplication()->run(\array_slice($args, 1), $stdin, $stdout, $stderr);
        }

        fwrite($stderr, $tool . ": not implemented\n");

        return self::EXIT_NOT_IMPLEMENTED;
    }
}
