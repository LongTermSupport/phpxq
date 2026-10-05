<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use Generator;
use LTS\PhpXq\Yq\Cli\HelpText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exact help and usage texts. The flag table is written out once; every other text carries a marker where
 * it goes.
 *
 * @internal
 */
#[CoversClass(HelpText::class)]
final class HelpTextTest extends TestCase
{
    private const string MARKER = '{flags}';

    private const string COMMAND_MARKER = '{command}';

    private const string SPACE_MARKER = '{space}';

    private const string EVAL = 'eval';

    private const string EVAL_ALL = 'eval-all';

    private const string COMPLETION = 'completion';

    private const string FLAGS = <<<'TEXT'
          -C, --colors                            force print with colors
              --csv-auto-parse                    parse CSV YAML/JSON values (default true)
              --csv-separator char                CSV Separator character (default ,)
          -e, --exit-status                       set exit status if there are no matches or null or false is returned
              --expression string                 forcibly set the expression argument. Useful when yq argument detection thinks your expression is a file.
              --from-file string                  Load expression from specified file.
          -f, --front-matter string               (extract|process) first input as yaml front-matter. Extract will pull out the yaml content, process will run the expression against the yaml content, leaving the remaining data intact
              --header-preprocess                 Slurp any header comments and separators before processing expression. (default true)
          -h, --help                              help for yq
          -I, --indent int                        sets indent level for output (default 2)
          -i, --inplace                           update the file in place of first file given.
          -p, --input-format string               [auto|a|yaml|y|json|j|props|p|csv|c|tsv|t|xml|x|base64|uri|toml|hcl|h|lua|l|ini|i] parse format for input. (default "auto")
              --lua-globals                       output keys as top-level global variables
              --lua-prefix string                 prefix (default "return ")
              --lua-suffix string                 suffix (default ";\n")
              --lua-unquoted                      output unquoted string keys (e.g. {foo="bar"})
          -M, --no-colors                         force print with no colors
          -N, --no-doc                            Don't print document separators (---)
          -0, --nul-output                        Use NUL char to separate values. If unwrap scalar is also set, fail if unwrapped scalar contains NUL char.
          -n, --null-input                        Don't read input, simply evaluate the expression given. Useful for creating docs from scratch.
          -o, --output-format string              [auto|a|yaml|y|json|j|props|p|csv|c|tsv|t|xml|x|base64|uri|toml|hcl|h|shell|s|lua|l|ini|i] output format type. (default "auto")
          -P, --prettyPrint                       pretty print, shorthand for '... style = ""'
              --properties-array-brackets         use [x] in array paths (e.g. for SpringBoot)
              --properties-separator string       separator to use between keys and values (default " = ")
              --security-disable-env-ops          Disable env related operations.
              --security-disable-file-ops         Disable file related operations (e.g. load)
              --security-enable-system-operator   Enable system operator
              --shell-key-separator string        separator for shell variable key paths (default "_")
          -s, --split-exp string                  print each result (or doc) into a file named (exp). [exp] argument must return a string. You can use $index in the expression as the result counter. The necessary directories will be created.
              --split-exp-file string             Use a file to specify the split-exp expression.
              --string-interpolation              Toggles strings interpolation of \(exp) (default true)
              --tsv-auto-parse                    parse TSV YAML/JSON values (default true)
          -r, --unwrapScalar                      unwrap scalar, print the value with no quotes, colours or comments. Defaults to true for yaml (default true)
          -v, --verbose                           verbose mode
          -V, --version                           Print version information and quit
              --xml-attribute-prefix string       prefix for xml attributes (default "+@")
              --xml-content-name string           name for xml content (if no attribute name is present). (default "+content")
              --xml-directive-name string         name for xml directives (e.g. <!DOCTYPE thing cat>) (default "+directive")
              --xml-keep-namespace                enables keeping namespace after parsing attributes (default true)
              --xml-proc-inst-prefix string       prefix for xml processing instructions (e.g. <?xml version="1"?>) (default "+p_")
              --xml-raw-token                     enables using RawToken method instead Token. Commonly disables namespace translations. See https://pkg.go.dev/encoding/xml#Decoder.RawToken for details. (default true)
              --xml-skip-directives               skip over directives (e.g. <!DOCTYPE thing cat>)
              --xml-skip-proc-inst                skip over process instructions (e.g. <?xml version="1"?>)
              --xml-strict-mode                   enables strict parsing of XML. See https://pkg.go.dev/encoding/xml for more details.
              --yaml-fix-merge-anchor-to-spec     Fix merge anchor to match YAML spec. Will default to true in late 2025

        TEXT;

    private const string ROOT_USAGE = <<<'TEXT'
        Usage:
          yq [flags]
          yq [command]

        Examples:

        # read the "stuff" node from "myfile.yml"
        yq '.stuff' < myfile.yml

        # update myfile.yml in place
        yq -i '.stuff = "foo"' myfile.yml

        # print contents of sample.json as idiomatic YAML
        yq -P -oy sample.json

        Available Commands:
          completion  Generate the autocompletion script for the specified shell
          {command}        (default) Apply the expression to each document in each yaml file in sequence
          {command}-all    Loads _all_ yaml documents of _all_ yaml files and runs expression once
          help        Help about any command

        Flags:
        {flags}
        Use "yq [command] --help" for more information about a command.

        TEXT;

    private const string ROOT_LONG = <<<'TEXT'
        yq is a portable command-line data file processor (https://github.com/mikefarah/yq/){space}
        See https://mikefarah.gitbook.io/yq/ for detailed documentation and examples.


        TEXT;

    private const string EVAL_USAGE = <<<'TEXT'
        Usage:
          yq {command} [expression] [yaml_file1]... [flags]

        Aliases:
          {command}, e

        Flags:
          -h, --help   help for {command}

        Global Flags:
        {flags}

        TEXT;

    private const string EVAL_ALL_USAGE = <<<'TEXT'
        Usage:
          yq {command}-all [expression] [yaml_file1]... [flags]

        Aliases:
          {command}-all, ea

        Flags:
          -h, --help   help for {command}-all

        Global Flags:
        {flags}

        TEXT;

    private const string COMPLETION_USAGE = <<<'TEXT'
        Usage:
          yq completion [command]

        Available Commands:
          bash        Generate the autocompletion script for bash
          fish        Generate the autocompletion script for fish
          powershell  Generate the autocompletion script for powershell
          zsh         Generate the autocompletion script for zsh

        Flags:
          -h, --help   help for completion

        Global Flags:
        {flags}
        Use "yq completion [command] --help" for more information about a command.

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

    private static function filled(string $template): string
    {
        return str_replace([self::MARKER, self::COMMAND_MARKER, self::SPACE_MARKER], [self::FLAGS, self::EVAL, ' '], $template);
    }

    public function testRootText(): void
    {
        self::assertSame(self::filled(self::ROOT_LONG . self::ROOT_USAGE), HelpText::root());
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function commandProvider(): Generator
    {
        yield self::EVAL => [self::EVAL, self::EVAL_LONG . self::EVAL_USAGE];

        yield 'eval alias' => ['e', self::EVAL_LONG . self::EVAL_USAGE];

        yield self::EVAL_ALL => [self::EVAL_ALL, self::EVAL_ALL_LONG . self::EVAL_ALL_USAGE];

        yield 'eval-all alias' => ['ea', self::EVAL_ALL_LONG . self::EVAL_ALL_USAGE];

        yield self::COMPLETION => [self::COMPLETION, self::COMPLETION_LONG . self::COMPLETION_USAGE];

        yield 'unknown command' => ['nope', self::ROOT_LONG . self::ROOT_USAGE];

        yield 'no command' => ['', self::ROOT_LONG . self::ROOT_USAGE];
    }

    #[DataProvider('commandProvider')]
    public function testCommandHelp(string $command, string $template): void
    {
        self::assertSame(self::filled($template), HelpText::forCommand($command));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function usageProvider(): Generator
    {
        yield self::EVAL => [self::EVAL, self::EVAL_USAGE];

        yield 'eval alias' => ['e', self::EVAL_USAGE];

        yield self::EVAL_ALL => [self::EVAL_ALL, self::EVAL_ALL_USAGE];

        yield 'eval-all alias' => ['ea', self::EVAL_ALL_USAGE];

        yield self::COMPLETION => [self::COMPLETION, self::COMPLETION_USAGE];

        yield 'unknown command' => ['nope', self::ROOT_USAGE];

        yield 'no command' => ['', self::ROOT_USAGE];
    }

    #[DataProvider('usageProvider')]
    public function testUsage(string $command, string $template): void
    {
        self::assertSame(self::filled($template), HelpText::usage($command));
    }
}
