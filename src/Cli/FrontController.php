<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

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

        fwrite($stderr, $tool . ": not implemented\n");

        return self::EXIT_NOT_IMPLEMENTED;
    }
}
