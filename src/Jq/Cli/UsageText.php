<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * The fixed texts of the jq command line, verbatim from jq 1.8. TARGET_VERSION is the jq release whose
 * behaviour phpxq's jq reproduces.
 *
 * @api
 */
final readonly class UsageText
{
    public const string TARGET_VERSION = '1.8.2';

    private const string SHORT = "Usage:\tjq [OPTIONS] FILTER [FILES...]\n\tjq [OPTIONS] --args FILTER [STRINGS...]\n\tjq [OPTIONS] --jsonargs FILTER [JSON_TEXTS...]\n\n";

    private const string HINT = "Use jq --help for help with command-line options,\nor see the jq manpage, or online docs  at https://jqlang.org\n";

    private const string LONG = <<<'TEXT'
        jq is a tool for processing JSON inputs, applying the given filter to
        its JSON text inputs and producing the filter's results as JSON on
        standard output.

        The simplest filter is ., which copies jq's input to its output
        unmodified except for formatting. For more advanced filters see
        the jq(1) manpage ("man jq") and/or https://jqlang.org/

        Example:

        	$ echo '{"foo": 0}' | jq .
        	{
        	  "foo": 0
        	}

        Command options:
          -n, --null-input          use `null` as the single input value;
          -R, --raw-input           read each line as string instead of JSON;
          -s, --slurp               read all inputs into an array and use it as
                                    the single input value;
          -c, --compact-output      compact instead of pretty-printed output;
          -r, --raw-output          output strings without escapes and quotes;
              --raw-output0         implies -r and output NUL after each output;
          -j, --join-output         implies -r and output without newline after
                                    each output;
          -a, --ascii-output        output strings by only ASCII characters
                                    using escape sequences;
          -S, --sort-keys           sort keys of each object on output;
          -C, --color-output        colorize JSON output;
          -M, --monochrome-output   disable colored output;
              --tab                 use tabs for indentation;
              --indent n            use n spaces for indentation (max 7 spaces);
              --unbuffered          flush output stream after each output;
              --stream              parse the input value in streaming fashion;
              --stream-errors       implies --stream and report parse error as
                                    an array;
              --seq                 parse input/output with application/json-seq
                                    MIME type;
          -f, --from-file file      load filter from the file;
          -L directory              search modules from the directory;
              --arg name value      set $name to the string value;
              --argjson name value  set $name to the JSON value;
              --slurpfile name file set $name to an array of JSON values read
                                    from the file;
              --rawfile name file   set $name to string contents of file;
              --args                consume remaining arguments as positional
                                    string values;
              --jsonargs            consume remaining arguments as positional
                                    JSON values;
          -e, --exit-status         set exit status code based on the output;
          -V, --version             show the version;
          --build-configuration     show jq's build configuration;
          -h, --help                show the help;
          --                        terminates argument processing;

        Named arguments are also available as $ARGS.named[], while
        positional arguments are available as $ARGS.positional[].

        TEXT;

    private function __construct()
    {
    }

    /**
     * `jq -h`: the whole text, for stdout.
     */
    public static function full(): string
    {
        return self::SHORT . self::LONG;
    }

    /**
     * The short usage block followed by the pointer to --help (a missing program).
     */
    public static function short(): string
    {
        return self::SHORT . self::HINT;
    }

    /**
     * The pointer to --help alone (a bad option).
     */
    public static function hint(): string
    {
        return self::HINT;
    }

    public static function version(): string
    {
        return 'jq-' . self::TARGET_VERSION . "\n";
    }

    public static function buildConfiguration(): string
    {
        return 'phpxq ' . \PHP_VERSION . " pure PHP\n";
    }
}
