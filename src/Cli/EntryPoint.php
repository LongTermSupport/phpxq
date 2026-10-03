<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

/**
 * Process-level dispatch shared by `bin/phpxq`, the PHAR and the static binary.
 *
 * Busybox style: when the program is called `jq` or `yq` (a symlink, a copy, or `jq.exe`) the tool is
 * implied and every argument belongs to it. Under any other name the first argument names the tool,
 * and `--version` as the sole argument reports the phpxq release from the VERSION file.
 *
 * Hook for the CLI owners: `jq --version` / `yq --version` reach the tool unchanged and are theirs to
 * answer; the packaged release number is available from {@see self::version()}.
 *
 * @api
 */
final readonly class EntryPoint
{
    private const array TOOLS = ['jq', 'yq'];

    public function __construct(
        private FrontControllerInterface $controller,
        private string $versionFile,
    ) {
    }

    /**
     * @param list<string> $args   arguments after the program name
     * @param resource     $stdin
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public function run(string $argv0, array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        $tool = $this->toolFromProgramName($argv0);
        if (null !== $tool) {
            return $this->controller->run([$tool, ...$args], $stdin, $stdout, $stderr);
        }

        if (['--version'] === $args) {
            fwrite($stdout, 'phpxq ' . $this->version() . "\n");

            return FrontControllerInterface::EXIT_OK;
        }

        return $this->controller->run($args, $stdin, $stdout, $stderr);
    }

    public function version(): string
    {
        if (!is_file($this->versionFile)) {
            return 'unknown';
        }

        $contents = trim((string)file_get_contents($this->versionFile));

        return '' === $contents ? 'unknown' : $contents;
    }

    private function toolFromProgramName(string $argv0): ?string
    {
        $name = strtolower(basename(str_replace('\\', '/', $argv0)));
        if (str_ends_with($name, '.exe')) {
            $name = substr($name, 0, -4);
        }

        return \in_array($name, self::TOOLS, true) ? $name : null;
    }
}
