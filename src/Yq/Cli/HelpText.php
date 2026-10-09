<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The help and usage texts, laid out the way cobra lays them out.
 *
 * @internal
 */
final readonly class HelpText
{
    private const string LONG = "yq is a portable command-line data file processor (https://github.com/mikefarah/yq/) \n"
        . 'See https://mikefarah.gitbook.io/yq/ for detailed documentation and examples.';

    private const string EXAMPLES = <<<'TEXT'

        # read the "stuff" node from "myfile.yml"
        yq '.stuff' < myfile.yml

        # update myfile.yml in place
        yq -i '.stuff = "foo"' myfile.yml

        # print contents of sample.json as idiomatic YAML
        yq -P -oy sample.json

        TEXT;

    private const string EVAL_LONG = <<<'TEXT'
        Outputs the results of the given expression, evaluated against each document of each file in sequence.
        Reads from stdin when no file is given. The expression defaults to '.'.
        TEXT;

    private const string EVAL_ALL_LONG = <<<'TEXT'
        Loads all documents of all files into memory, then runs the expression once against all of them.
        Useful for merging files, or for operators that need to see more than one document.
        TEXT;

    private const string COMPLETION_LONG = <<<'TEXT'
        Generate the autocompletion script for yq for the specified shell.
        See each sub-command's help for details on how to use the generated script.
        TEXT;

    /**
     * The text of `yq --help`, `yq -h` and `yq help`.
     */
    public static function root(): string
    {
        return self::LONG . "\n\n" . self::usage('');
    }

    /**
     * The text of `yq <command> --help` and `yq help <command>`; unknown commands get the root text.
     */
    public static function forCommand(string $command): string
    {
        return match (CommandEnum::tryFrom($command)?->canonical()) {
            CommandEnum::Eval       => self::EVAL_LONG . "\n\n" . self::usage(CommandEnum::Eval->value),
            CommandEnum::EvalAll    => self::EVAL_ALL_LONG . "\n\n" . self::usage(CommandEnum::EvalAll->value),
            CommandEnum::Completion => self::COMPLETION_LONG . "\n\n" . self::usage(CommandEnum::Completion->value),
            default                 => self::root(),
        };
    }

    /**
     * The usage block printed after a usage error, and the tail of every help text.
     */
    public static function usage(string $command): string
    {
        return match (CommandEnum::tryFrom($command)?->canonical()) {
            CommandEnum::Eval       => self::commandUsage('eval [expression] [yaml_file1]...', "\n\nAliases:\n  eval, e"),
            CommandEnum::EvalAll    => self::commandUsage('eval-all [expression] [yaml_file1]...', "\n\nAliases:\n  eval-all, ea"),
            CommandEnum::Completion => "Usage:\n  yq completion [command]\n\nAvailable Commands:\n"
                . "  bash        Generate the autocompletion script for bash\n"
                . "  fish        Generate the autocompletion script for fish\n"
                . "  powershell  Generate the autocompletion script for powershell\n"
                . "  zsh         Generate the autocompletion script for zsh\n\n"
                . "Flags:\n  -h, --help   help for completion\n\n" . self::globalFlags()
                . "\nUse \"yq completion [command] --help\" for more information about a command.\n",
            default                 => "Usage:\n  yq [flags]\n  yq [command]\n\nExamples:\n" . self::EXAMPLES . "\n"
                . "Available Commands:\n"
                . "  completion  Generate the autocompletion script for the specified shell\n"
                . "  eval        (default) Apply the expression to each document in each yaml file in sequence\n"
                . "  eval-all    Loads _all_ yaml documents of _all_ yaml files and runs expression once\n"
                . "  help        Help about any command\n\n"
                . "Flags:\n" . self::flagLines() . "\n"
                . "Use \"yq [command] --help\" for more information about a command.\n",
        };
    }

    private static function commandUsage(string $use, string $aliases): string
    {
        return 'Usage:' . "\n  yq " . $use . ' [flags]' . $aliases . "\n\nFlags:\n  -h, --help   help for "
            . strtok($use, ' ') . "\n\n" . self::globalFlags() . "\n";
    }

    private static function globalFlags(): string
    {
        return "Global Flags:\n" . self::flagLines();
    }

    private static function flagLines(): string
    {
        $rows = [];
        $max  = 0;
        foreach (FlagCatalog::all() as $spec) {
            if ($spec->hidden) {
                continue;
            }

            $left = '  ' . ('' === $spec->short ? '    ' : '-' . $spec->short . ', ') . '--' . $spec->name;
            $name = '' !== $spec->valueName ? $spec->valueName : match ($spec->type) {
                FlagTypeEnum::String => 'string',
                FlagTypeEnum::Int    => 'int',
                FlagTypeEnum::Bool   => '',
            };
            if ('' !== $name) {
                $left .= ' ' . $name;
            }

            $right  = $spec->usage . ('' === $spec->defaultText ? '' : ' (default ' . $spec->defaultText . ')');
            $rows[] = [$left, $right];
            $max    = max($max, \strlen($left));
        }

        $out = '';
        foreach ($rows as [$left, $right]) {
            $out .= $left . str_repeat(' ', $max - \strlen($left) + 3) . $right . "\n";
        }

        return $out;
    }
}
