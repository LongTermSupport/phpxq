<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The outcome of parsing a command line: the sub-command, the value of every flag (defaults filled in),
 * which flags were given explicitly, and the remaining positional arguments in order.
 */
final readonly class ParsedArguments
{
    /**
     * @param array<string, bool|int|string> $values      flag name to value, defaults included
     * @param array<string, true>            $given       flags that appeared on the command line
     * @param list<string>                   $positionals
     */
    public function __construct(
        public string $command,
        public array $values,
        public array $given,
        public array $positionals,
    ) {
    }

    public function bool(string $name): bool
    {
        $value = $this->values[$name] ?? false;

        return true === $value;
    }

    public function string(string $name): string
    {
        $value = $this->values[$name] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function int(string $name): int
    {
        $value = $this->values[$name] ?? 0;

        return \is_int($value) ? $value : 0;
    }

    public function given(string $name): bool
    {
        return isset($this->given[$name]);
    }
}
