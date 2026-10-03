<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli\Options;

use LTS\PhpXq\Json\ColorScheme;
use LTS\PhpXq\Json\EncodeOptions;

/**
 * The parsed jq command line.
 *
 * The three layout fields mirror jq's dump flags: `-c` clears $pretty, `--tab` sets $tab and $pretty,
 * `--indent n` sets $indent (and $tab for -1) without touching $pretty, so the order of the flags on the
 * command line decides the layout exactly as it does in jq.
 *
 * @api
 */
final readonly class CliOptions
{
    /**
     * @param list<string>         $files        input files (`-` is stdin)
     * @param list<string>         $libraryPaths `-L` directories
     * @param array<string, mixed> $named        `--arg`, `--argjson`, `--slurpfile`, `--rawfile` in order
     * @param list<mixed>          $positional   `--args` / `--jsonargs` values
     * @param ?bool                $color        true for -C, false for -M, null for "decide from the terminal"
     */
    public function __construct(
        public CliAction $action = CliAction::Run,
        public ?string $program = null,
        public array $files = [],
        public array $libraryPaths = [],
        public array $named = [],
        public array $positional = [],
        public bool $nullInput = false,
        public bool $rawInput = false,
        public bool $slurp = false,
        public bool $rawOutput = false,
        public bool $rawOutput0 = false,
        public bool $joinOutput = false,
        public bool $ascii = false,
        public bool $sortKeys = false,
        public ?bool $color = null,
        public bool $pretty = true,
        public bool $tab = false,
        public int $indent = 2,
        public bool $exitStatus = false,
        public bool $seq = false,
        public bool $stream = false,
        public bool $streamErrors = false,
        public bool $unbuffered = false,
        public bool $fromFile = false,
        public bool $debugDumpDisasm = false,
    ) {
    }

    /**
     * `--indent 0` without `-c` still puts every element on its own line, just without indentation, a
     * layout {@see EncodeOptions} cannot express: the application indents by one and strips the indent.
     */
    public function isFlatPretty(): bool
    {
        return $this->pretty && !$this->tab && 0 === $this->indent;
    }

    public function encodeOptions(?ColorScheme $colors): EncodeOptions
    {
        if (!$this->pretty) {
            return new EncodeOptions(0, false, $this->sortKeys, $this->ascii, $colors);
        }

        if ($this->tab) {
            return new EncodeOptions(1, true, $this->sortKeys, $this->ascii, $colors);
        }

        return new EncodeOptions($this->isFlatPretty() ? 1 : $this->indent, false, $this->sortKeys, $this->ascii, $colors);
    }

    /**
     * Names (without `$`) of the global variables the program may use: the three predefined ones and every
     * named argument.
     *
     * @return list<string>
     */
    public function globalNames(): array
    {
        return array_values(array_unique([
            'ENV',
            '__prog_args',
            'ARGS',
            ...array_map(strval(...), array_keys($this->named)),
        ]));
    }
}
