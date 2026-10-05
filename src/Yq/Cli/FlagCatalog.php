<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * Every flag the reference `yq` command line accepts, in the order its help lists them (sorted by name).
 */
final class FlagCatalog
{
    private const string TRUE_TEXT = 'true';

    private const string FORMATS_IN = '[auto|a|yaml|y|json|j|props|p|csv|c|tsv|t|xml|x|base64|uri|toml|hcl|h|lua|l|ini|i]';

    private const string FORMATS_OUT = '[auto|a|yaml|y|json|j|props|p|csv|c|tsv|t|xml|x|base64|uri|toml|hcl|h|shell|s|lua|l|ini|i]';

    /** @var list<FlagSpec>|null */
    private static ?array $specs = null;

    /** @var array<string, FlagSpec>|null */
    private static ?array $byName = null;

    /** @var array<string, FlagSpec>|null */
    private static ?array $byShort = null;

    /**
     * @return list<FlagSpec>
     */
    public static function all(): array
    {
        return self::$specs ??= self::build();
    }

    public static function byName(string $name): ?FlagSpec
    {
        if (null === self::$byName) {
            self::$byName = [];
            foreach (self::all() as $spec) {
                self::$byName[$spec->name] = $spec;
            }
        }

        return self::$byName[$name] ?? null;
    }

    public static function byShort(string $short): ?FlagSpec
    {
        if (null === self::$byShort) {
            self::$byShort = [];
            foreach (self::all() as $spec) {
                if ('' !== $spec->short) {
                    self::$byShort[$spec->short] = $spec;
                }
            }
        }

        return self::$byShort[$short] ?? null;
    }

    /**
     * @return list<FlagSpec>
     */
    private static function build(): array
    {
        return [
            new FlagSpec('colors', 'C', FlagTypeEnum::Bool, false, 'force print with colors'),
            new FlagSpec('csv-auto-parse', '', FlagTypeEnum::Bool, true, 'parse CSV YAML/JSON values', '', self::TRUE_TEXT),
            new FlagSpec('csv-separator', '', FlagTypeEnum::String, ',', 'CSV Separator character', 'char', ','),
            new FlagSpec('exit-status', 'e', FlagTypeEnum::Bool, false, 'set exit status if there are no matches or null or false is returned'),
            new FlagSpec('expression', '', FlagTypeEnum::String, '', 'forcibly set the expression argument. Useful when yq argument detection thinks your expression is a file.'),
            new FlagSpec('from-file', '', FlagTypeEnum::String, '', 'Load expression from specified file.'),
            new FlagSpec('front-matter', 'f', FlagTypeEnum::String, '', '(extract|process) first input as yaml front-matter. Extract will pull out the yaml content, process will run the expression against the yaml content, leaving the remaining data intact'),
            new FlagSpec('header-preprocess', '', FlagTypeEnum::Bool, true, 'Slurp any header comments and separators before processing expression.', '', self::TRUE_TEXT),
            new FlagSpec('help', 'h', FlagTypeEnum::Bool, false, 'help for yq'),
            new FlagSpec('indent', 'I', FlagTypeEnum::Int, 2, 'sets indent level for output', '', '2'),
            new FlagSpec('inplace', 'i', FlagTypeEnum::Bool, false, 'update the file in place of first file given.'),
            new FlagSpec('input-format', 'p', FlagTypeEnum::String, 'auto', self::FORMATS_IN . ' parse format for input.', '', '"auto"'),
            new FlagSpec('lua-globals', '', FlagTypeEnum::Bool, false, 'output keys as top-level global variables'),
            new FlagSpec('lua-prefix', '', FlagTypeEnum::String, 'return ', 'prefix', '', '"return "'),
            new FlagSpec('lua-suffix', '', FlagTypeEnum::String, ";\n", 'suffix', '', '";\n"'),
            new FlagSpec('lua-unquoted', '', FlagTypeEnum::Bool, false, 'output unquoted string keys (e.g. {foo="bar"})'),
            new FlagSpec('no-colors', 'M', FlagTypeEnum::Bool, false, 'force print with no colors'),
            new FlagSpec('no-doc', 'N', FlagTypeEnum::Bool, false, "Don't print document separators (---)"),
            new FlagSpec('nul-output', '0', FlagTypeEnum::Bool, false, 'Use NUL char to separate values. If unwrap scalar is also set, fail if unwrapped scalar contains NUL char.'),
            new FlagSpec('null-input', 'n', FlagTypeEnum::Bool, false, "Don't read input, simply evaluate the expression given. Useful for creating docs from scratch."),
            new FlagSpec('output-format', 'o', FlagTypeEnum::String, 'auto', self::FORMATS_OUT . ' output format type.', '', '"auto"'),
            new FlagSpec('prettyPrint', 'P', FlagTypeEnum::Bool, false, 'pretty print, shorthand for \'... style = ""\''),
            new FlagSpec('properties-array-brackets', '', FlagTypeEnum::Bool, false, 'use [x] in array paths (e.g. for SpringBoot)'),
            new FlagSpec('properties-separator', '', FlagTypeEnum::String, ' = ', 'separator to use between keys and values', '', '" = "'),
            new FlagSpec('security-disable-env-ops', '', FlagTypeEnum::Bool, false, 'Disable env related operations.'),
            new FlagSpec('security-disable-file-ops', '', FlagTypeEnum::Bool, false, 'Disable file related operations (e.g. load)'),
            new FlagSpec('security-enable-system-operator', '', FlagTypeEnum::Bool, false, 'Enable system operator'),
            new FlagSpec('shell-key-separator', '', FlagTypeEnum::String, '_', 'separator for shell variable key paths', '', '"_"'),
            new FlagSpec('split-exp', 's', FlagTypeEnum::String, '', 'print each result (or doc) into a file named (exp). [exp] argument must return a string. You can use $index in the expression as the result counter. The necessary directories will be created.'),
            new FlagSpec('split-exp-file', '', FlagTypeEnum::String, '', 'Use a file to specify the split-exp expression.'),
            new FlagSpec('string-interpolation', '', FlagTypeEnum::Bool, true, 'Toggles strings interpolation of \(exp)', '', self::TRUE_TEXT),
            new FlagSpec('toyaml', 'y', FlagTypeEnum::Bool, false, 'output as yaml', '', '', true),
            new FlagSpec('tojson', 'j', FlagTypeEnum::Bool, false, 'output as json', '', '', true),
            new FlagSpec('tsv-auto-parse', '', FlagTypeEnum::Bool, true, 'parse TSV YAML/JSON values', '', self::TRUE_TEXT),
            new FlagSpec('unwrapScalar', 'r', FlagTypeEnum::Bool, true, 'unwrap scalar, print the value with no quotes, colours or comments. Defaults to true for yaml', '', self::TRUE_TEXT),
            new FlagSpec('verbose', 'v', FlagTypeEnum::Bool, false, 'verbose mode'),
            new FlagSpec('version', 'V', FlagTypeEnum::Bool, false, 'Print version information and quit'),
            new FlagSpec('xml-attribute-prefix', '', FlagTypeEnum::String, '+@', 'prefix for xml attributes', '', '"+@"'),
            new FlagSpec('xml-content-name', '', FlagTypeEnum::String, '+content', 'name for xml content (if no attribute name is present).', '', '"+content"'),
            new FlagSpec('xml-directive-name', '', FlagTypeEnum::String, '+directive', 'name for xml directives (e.g. <!DOCTYPE thing cat>)', '', '"+directive"'),
            new FlagSpec('xml-keep-namespace', '', FlagTypeEnum::Bool, true, 'enables keeping namespace after parsing attributes', '', self::TRUE_TEXT),
            new FlagSpec('xml-proc-inst-prefix', '', FlagTypeEnum::String, '+p_', 'prefix for xml processing instructions (e.g. <?xml version="1"?>)', '', '"+p_"'),
            new FlagSpec('xml-raw-token', '', FlagTypeEnum::Bool, true, 'enables using RawToken method instead Token. Commonly disables namespace translations. See https://pkg.go.dev/encoding/xml#Decoder.RawToken for details.', '', self::TRUE_TEXT),
            new FlagSpec('xml-skip-directives', '', FlagTypeEnum::Bool, false, 'skip over directives (e.g. <!DOCTYPE thing cat>)'),
            new FlagSpec('xml-skip-proc-inst', '', FlagTypeEnum::Bool, false, 'skip over process instructions (e.g. <?xml version="1"?>)'),
            new FlagSpec('xml-strict-mode', '', FlagTypeEnum::Bool, false, 'enables strict parsing of XML. See https://pkg.go.dev/encoding/xml for more details.'),
            new FlagSpec('yaml-fix-merge-anchor-to-spec', '', FlagTypeEnum::Bool, false, 'Fix merge anchor to match YAML spec. Will default to true in late 2025'),
        ];
    }
}
