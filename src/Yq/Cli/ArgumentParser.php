<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * Parses a `yq` command line the way cobra and pflag do: an optional sub-command (the first argument
 * that is not a flag, when it names a command), interspersed flags and positionals, `--flag=value`,
 * `--flag value`, clustered shorthands (`-inP`), `-oy` and `-o=json` value forms, `--` ending the flags,
 * and a lone `-` as a positional (standard input).
 */
final readonly class ArgumentParser
{
    /** The words that select a subcommand when they come first, aliases included. */
    public const array COMMANDS = [
        CommandEnum::Eval->value,
        CommandEnum::EvalShort->value,
        CommandEnum::EvalAll->value,
        CommandEnum::EvalAllShort->value,
        CommandEnum::Completion->value,
        CommandEnum::Help->value,
        CommandEnum::Complete->value,
        CommandEnum::CompleteNoDescriptions->value,
    ];
    /** The spellings Go's `strconv.ParseBool` reads as true. */
    private const array TRUE_SPELLINGS = ['1', 't', 'T', 'TRUE', 'true', 'True'];

    /** The spellings Go's `strconv.ParseBool` reads as false. */
    private const array FALSE_SPELLINGS = ['0', 'f', 'F', 'FALSE', 'false', 'False'];

    /**
     * @throws UsageException
     */
    public function parse(string ...$args): ParsedArguments
    {
        [$command, $args] = $this->extractCommand(...$args);

        $values      = [];
        foreach (FlagCatalog::all() as $spec) {
            $values[$spec->name] = $spec->default;
        }

        $given       = [];
        $positionals = [];
        $count       = \count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            if ('--' === $arg) {
                foreach (\array_slice($args, $i + 1) as $rest) {
                    $positionals[] = $rest;
                }

                break;
            }

            if ('' === $arg || '-' === $arg || '-' !== $arg[0]) {
                $positionals[] = $arg;

                continue;
            }

            if ('-' === $arg[1]) {
                $i = $this->parseLong($i, $values, $given, ...$args);

                continue;
            }

            $i = $this->parseShort($i, $values, $given, ...$args);
        }

        $this->applyShortcuts($values, $given);

        return new ParsedArguments($command, $values, $given, $positionals);
    }

    /**
     * Finds the sub-command: the first argument that is neither a flag nor a flag's value, if it names a
     * command. Returns the command ('' for the root) and the arguments without it.
     *
     * @return array{string, list<string>}
     */
    private function extractCommand(string ...$args): array
    {
        $count = \count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            if ('--' === $arg || '-' === $arg) {
                break;
            }

            if ('' === $arg || '-' !== $arg[0]) {
                if (!\in_array($arg, self::COMMANDS, true)) {
                    break;
                }

                $rest = $args;
                unset($rest[$i]);

                return [$arg, array_values($rest)];
            }

            if ($this->flagTakesNextArgument($arg)) {
                ++$i;
            }
        }

        return ['', array_values($args)];
    }

    private function flagTakesNextArgument(string $arg): bool
    {
        if ('-' === $arg[1]) {
            if (str_contains($arg, '=')) {
                return false;
            }

            $spec = FlagCatalog::byName(substr($arg, 2));

            return $spec instanceof FlagSpec && FlagTypeEnum::Bool !== $spec->type;
        }

        $length = \strlen($arg);
        for ($j = 1; $j < $length; ++$j) {
            $spec = FlagCatalog::byShort($arg[$j]);
            if (!$spec instanceof FlagSpec) {
                return false;
            }

            if (FlagTypeEnum::Bool !== $spec->type) {
                return $j === $length - 1;
            }

            if ($j + 1 < $length && '=' === $arg[$j + 1]) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool|int|string> $values
     * @param array<string, true>            $given
     */
    private function parseLong(int $index, array &$values, array &$given, string ...$args): int
    {
        $body     = substr($args[$index], 2);
        $equals   = strpos($body, '=');
        $name     = false === $equals ? $body : substr($body, 0, $equals);
        $inline   = false === $equals ? null : substr($body, $equals + 1);
        $spec     = FlagCatalog::byName($name);
        if (!$spec instanceof FlagSpec) {
            throw new UsageException('unknown flag: --' . $name);
        }

        if (FlagTypeEnum::Bool === $spec->type) {
            $values[$spec->name] = null === $inline ? true : $this->parseBool($spec, $inline);
            $given[$spec->name]  = true;

            return $index;
        }

        if (null === $inline) {
            if (!isset($args[$index + 1])) {
                throw new UsageException('flag needs an argument: --' . $name);
            }

            $inline = $args[++$index];
        }

        $values[$spec->name] = $this->convert($spec, $inline);
        $given[$spec->name]  = true;

        return $index;
    }

    /**
     * @param array<string, bool|int|string> $values
     * @param array<string, true>            $given
     */
    private function parseShort(int $index, array &$values, array &$given, string ...$args): int
    {
        $cluster = substr($args[$index], 1);
        $length  = \strlen($cluster);
        for ($j = 0; $j < $length; ++$j) {
            $spec = FlagCatalog::byShort($cluster[$j]);
            if (!$spec instanceof FlagSpec) {
                throw new UsageException(\sprintf("unknown shorthand flag: '%s' in -%s", $cluster[$j], substr($cluster, $j)));
            }

            $given[$spec->name] = true;
            if (FlagTypeEnum::Bool === $spec->type) {
                if ($j + 1 < $length && '=' === $cluster[$j + 1]) {
                    $values[$spec->name] = $this->parseBool($spec, substr($cluster, $j + 2));

                    return $index;
                }

                $values[$spec->name] = true;

                continue;
            }

            if ($j + 1 < $length) {
                $raw = substr($cluster, $j + 1);
                if ('=' === $raw[0]) {
                    $raw = substr($raw, 1);
                }
            } elseif (isset($args[$index + 1])) {
                $raw = $args[++$index];
            } else {
                throw new UsageException(\sprintf("flag needs an argument: '%s' in -%s", $cluster[$j], $cluster[$j]));
            }

            $values[$spec->name] = $this->convert($spec, $raw);

            return $index;
        }

        return $index;
    }

    private function convert(FlagSpec $spec, string $raw): int|string
    {
        if (FlagTypeEnum::Int !== $spec->type) {
            return $raw;
        }

        if (1 !== preg_match('/^[+-]?\d+$/', $raw)) {
            throw new UsageException(\sprintf(
                'invalid argument "%s" for "%s" flag: strconv.ParseInt: parsing "%s": invalid syntax',
                $raw,
                $spec->label(),
                $raw,
            ));
        }

        return (int)$raw;
    }

    private function parseBool(FlagSpec $spec, string $raw): bool
    {
        if (\in_array($raw, self::TRUE_SPELLINGS, true)) {
            return true;
        }

        if (\in_array($raw, self::FALSE_SPELLINGS, true)) {
            return false;
        }

        throw new UsageException(\sprintf(
            'invalid argument "%s" for "%s" flag: strconv.ParseBool: parsing "%s": invalid syntax',
            $raw,
            $spec->label(),
            $raw,
        ));
    }

    /**
     * The hidden `-j` and `-y` shortcuts select the output format unless `-o` was given.
     *
     * @param array<string, bool|int|string> $values
     * @param array<string, true>            $given
     */
    private function applyShortcuts(array &$values, array &$given): void
    {
        if (isset($given['output-format'])) {
            return;
        }

        if (true === $values['tojson']) {
            $values['output-format'] = 'json';
            $given['output-format']  = true;
        } elseif (true === $values['toyaml']) {
            $values['output-format'] = 'yaml';
            $given['output-format']  = true;
        }
    }
}
