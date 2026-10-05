<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

/**
 * Process-level dispatch shared by `bin/phpxq`, the PHAR and the static binary.
 *
 * Busybox style: when the program is called `jq` or `yq` (a symlink, a copy, or `jq.exe`) the tool is
 * implied and every argument belongs to it. Under any other name the first argument names the tool,
 * and `--version` as the sole argument reports the phpxq release from the VERSION file followed by the
 * jq and yq releases the tools are compatible with. `jq --version` / `yq --version` reach the tool
 * unchanged and print exactly what upstream prints.
 *
 * @api
 */
final readonly class EntryPoint
{
    public function __construct(
        private FrontControllerInterface $controller,
        private string $versionFile,
    ) {
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     * @param string   ...$args arguments after the program name
     */
    public function run(string $argv0, mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        $tool = ToolEnum::fromProgramName($argv0);
        if ($tool instanceof ToolEnum) {
            return $this->controller->run($stdin, $stdout, $stderr, $tool->value, ...$args);
        }

        if (['--version'] === $args) {
            fwrite($stdout, $this->versionReport());

            return FrontControllerInterface::EXIT_OK;
        }

        return $this->controller->run($stdin, $stdout, $stderr, ...$args);
    }

    public function version(): string
    {
        if (!is_file($this->versionFile)) {
            return 'unknown';
        }

        $contents = trim((string)file_get_contents($this->versionFile));

        return '' === $contents ? 'unknown' : $contents;
    }

    /**
     * The first line is always `phpxq X.Y.Z` (scripts and the installer match on it); the following lines
     * name the upstream releases the tools are compatible with, in the shape those tools print themselves.
     */
    private function versionReport(): string
    {
        return 'phpxq ' . $this->version() . "\n"
            . implode('', array_map(static fn (ToolEnum $tool): string => $tool->versionLine(), ToolEnum::cases()));
    }
}
