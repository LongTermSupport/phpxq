<?php

declare(strict_types=1);

namespace LTS\PhpXq\Cli;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Cli\UsageText;
use LTS\PhpXq\Yq\Cli\YqApplication;

/**
 * The tools phpxq provides. The backing value is the command name: the first argument under the
 * umbrella `phpxq` program, and the program name under busybox-style invocation.
 *
 * @api
 */
enum ToolEnum: string
{
    private const string EXE_SUFFIX = '.exe';

    public static function usage(): string
    {
        $names = implode('|', array_map(static fn (self $tool): string => $tool->value, self::cases()));

        return \sprintf("usage: phpxq %s [arguments...]\n", $names);
    }

    /**
     * Resolves `jq`, `/usr/bin/yq` or `C:\bin\jq.exe`; any other program name is not a tool.
     */
    public static function fromProgramName(string $argv0): ?self
    {
        $name = strtolower(basename(str_replace('\\', '/', $argv0)));
        if (str_ends_with($name, self::EXE_SUFFIX)) {
            $name = substr($name, 0, -\strlen(self::EXE_SUFFIX));
        }

        return self::tryFrom($name);
    }

    /**
     * The line `phpxq --version` prints for this tool, in the shape the upstream tool prints its own.
     */
    public function versionLine(): string
    {
        return match ($this) {
            self::Jq => 'jq-' . UsageText::TARGET_VERSION . " compatible ({$this->value})\n",
            self::Yq => 'yq ' . YqApplication::REFERENCE_VERSION . " compatible ({$this->value})\n",
        };
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     * @param string   ...$args the tool's own command line, without the tool name
     */
    public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        return match ($this) {
            self::Jq => JqApplication::create()->run($stdin, $stdout, $stderr, ...$args),
            self::Yq => new YqApplication()->run($stdin, $stdout, $stderr, ...$args),
        };
    }
    case Jq = 'jq';
    case Yq = 'yq';
}
