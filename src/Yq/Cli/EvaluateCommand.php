<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitterInterface;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Expression\ExpressionParserInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;
use LTS\PhpXq\Yq\Runtime\Candidate;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;
use LTS\PhpXq\Yq\Runtime\RuntimeServices;
use LTS\PhpXq\Yq\Runtime\SecurityOptions;
use Throwable;

/**
 * `yq eval` and `yq eval-all` (and the bare `yq`, which is `eval`): resolves the expression and the
 * files from the arguments, reads the documents, evaluates, and prints the results, in place or to split
 * files when asked.
 *
 * `eval` evaluates the expression once per document, printing as it goes; `eval-all` loads every document
 * of every file first and evaluates once over all of them.
 */
final class EvaluateCommand
{
    private const string FORMAT_LIST = 'yaml|json|props|csv|tsv|xml|base64|uri|toml|hcl|shell|lua|kyaml';

    public function __construct(
        private readonly YamlParserInterface $yamlParser,
        private readonly YamlEmitterInterface $emitter,
        private readonly ExpressionParserInterface $expressions,
        private readonly EvaluatorInterface $evaluator,
        private readonly FormatRegistryInterface $formats,
        private readonly FormatDetector $detector = new FormatDetector(),
    ) {
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     *
     * @throws CliException
     */
    public function run(ParsedArguments $args, bool $evalAll, mixed $stdin, mixed $stdout): int
    {
        [$expression, $files] = $this->resolveExpression($args);
        $splitExpression      = $this->splitExpression($args);
        $nullInput            = $args->bool('null-input');
        $inPlace              = $args->bool('inplace');

        $this->validate($args, $files, $splitExpression, $nullInput, $inPlace);

        if (!$nullInput && [] === $files) {
            if (stream_isatty($stdin)) {
                fwrite($stdout, HelpText::root());

                return YqApplicationInterface::EXIT_OK;
            }

            $files = ['-'];
        }

        [$inputFormat, $autoInput] = $this->inputFormat($args, $files);
        $outputFormat              = $this->outputFormat($args, $inputFormat, $autoInput);

        $colors = $args->bool('colors')
            || (!$args->bool('no-colors') && !$inPlace && '' === $splitExpression && stream_isatty($stdout));
        $unwrap = $args->bool('unwrapScalar');
        if (!$args->given('unwrapScalar') && Format::Json === $outputFormat) {
            $unwrap = false;
        }

        $emitOptions = new EmitOptions(
            $args->int('indent'),
            $colors,
            $unwrap,
            $args->bool('prettyPrint'),
            $args->bool('no-doc'),
        );

        $services = new RuntimeServices(
            $this->expressions,
            $this->yamlParser,
            $this->formats,
            new SecurityOptions(
                $args->bool('security-enable-system-operator'),
                $args->bool('security-disable-env-ops'),
                $args->bool('security-disable-file-ops'),
            ),
        );

        $registry = new DocumentRegistry();
        $source   = new SourceDocuments($this->yamlParser, $this->formats, $registry);
        $program  = $this->expressions->parse('' === $expression ? '.' : $expression);

        [$inputs, $appendix] = $this->inputs($args, $files, $source, $stdin);

        $target = $inPlace ? new InPlaceTarget($files[0]) : null;
        $split  = '' === $splitExpression
            ? null
            : new SplitFileWriter($this->expressions->parse($splitExpression), $this->evaluator, $services, $outputFormat);

        $printer = new ResultPrinter(
            $target?->stream() ?? $stdout,
            $outputFormat,
            $emitOptions,
            $this->formatOptions($args, $outputFormat, $colors, $unwrap),
            $this->emitter,
            $this->formats,
            $registry,
            $args->bool('nul-output'),
            $split,
        );

        $succeeded = false;
        try {
            if ($nullInput) {
                $printer->print($this->evaluator->evaluate($program, new EvaluationContext([new Candidate(Node::scalar('null', '!!null'))], $services)), '');
            } else {
                $documents = $source->read($inputs, $stdin, $inputFormat, $this->formatOptions($args, $inputFormat, false, true), $args->bool('header-preprocess') && !$evalAll);
                if ($evalAll) {
                    $all = iterator_to_array($documents, false);
                    $printer->print($this->evaluator->evaluate($program, new EvaluationContext($all, $services)));
                } else {
                    foreach ($documents as $candidate) {
                        $printer->print(
                            $this->evaluator->evaluate($program, new EvaluationContext([$candidate], $services)),
                            $candidate->fileIndex . ':' . $candidate->documentIndex,
                        );
                    }
                }
            }

            $succeeded = true;
        } catch (Throwable $e) {
            $printer->finish('');

            throw $e;
        }

        $printer->finish($appendix);
        $target?->commit();

        if ($succeeded && $args->bool('exit-status') && !$printer->printedAnything()) {
            throw new CliException('no matches found');
        }

        return YqApplicationInterface::EXIT_OK;
    }

    /**
     * The expression and the files. `--expression` and `--from-file` force the expression; otherwise the
     * first positional is the expression unless it is `-` or an existing file (a file holding a shebang
     * line and marked executable is a script: its body is the expression).
     *
     * @return array{string, list<string>}
     *
     * @throws CliException
     */
    private function resolveExpression(ParsedArguments $args): array
    {
        $positionals = $args->positionals;
        $expression  = $args->string('expression');

        $fromFile = $args->string('from-file');
        if ('' !== $fromFile) {
            $expression = $this->readExpressionFile($fromFile);
        }

        $first = $positionals[0] ?? null;
        if ('' === $expression && null !== $first && '-' !== $first) {
            if (is_file($first)) {
                if (is_executable($first) && str_starts_with((string)file_get_contents($first), '#!')) {
                    $expression = $this->readExpressionFile($first);
                    array_shift($positionals);
                }
            } else {
                $expression = $first;
                array_shift($positionals);
            }
        }

        return [$expression, $positionals];
    }

    /**
     * @throws CliException
     */
    private function readExpressionFile(string $path): string
    {
        if (!is_file($path)) {
            throw new CliException(\sprintf('open %s: no such file or directory', $path));
        }

        $text = (string)file_get_contents($path);
        if (str_starts_with($text, '#!')) {
            $newline = strpos($text, "\n");
            $text    = false === $newline ? '' : substr($text, $newline + 1);
        }

        return rtrim($text, " \t\r\n");
    }

    /**
     * @throws CliException
     */
    private function splitExpression(ParsedArguments $args): string
    {
        $file = $args->string('split-exp-file');
        if ('' !== $file) {
            return $this->readExpressionFile($file);
        }

        return $args->string('split-exp');
    }

    /**
     * @param list<string> $files
     *
     * @throws CliException
     */
    private function validate(ParsedArguments $args, array $files, string $splitExpression, bool $nullInput, bool $inPlace): void
    {
        if ($inPlace && ([] === $files || '-' === $files[0])) {
            throw new CliException('write in place flag only applicable when giving an expression and at least one file');
        }

        if ($inPlace && '' !== $splitExpression) {
            throw new CliException('write in place cannot be used with split file');
        }

        if ($nullInput && [] !== $files) {
            throw new CliException('cannot pass files in when using null-input flag');
        }

        $frontMatter = $args->string('front-matter');
        if ('' !== $frontMatter && !\in_array($frontMatter, ['extract', 'process'], true)) {
            throw new CliException("front-matter must be 'extract' or 'process'");
        }
    }

    /**
     * @param list<string> $files
     *
     * @return array{Format, bool} the input format and whether it was auto-detected
     *
     * @throws CliException
     */
    private function inputFormat(ParsedArguments $args, array $files): array
    {
        $name = $args->string('input-format');
        if (\in_array($name, ['auto', 'a', ''], true)) {
            $first = $files[0] ?? '-';

            return ['-' === $first ? Format::Yaml : $this->detector->fromFilename($first), true];
        }

        $format = Format::fromName($name);
        if (!$format instanceof Format) {
            throw new CliException(\sprintf("unknown format '%s' please use [%s]", $name, self::FORMAT_LIST));
        }

        if (!$format->canDecode()) {
            throw new CliException(\sprintf('no support for %s input format', $name));
        }

        return [$format, false];
    }

    /**
     * @throws CliException
     */
    private function outputFormat(ParsedArguments $args, Format $inputFormat, bool $autoInput): Format
    {
        $name = $args->string('output-format');
        if (\in_array($name, ['auto', 'a', ''], true)) {
            return $autoInput ? $inputFormat : Format::Yaml;
        }

        $format = Format::fromName($name);
        if (!$format instanceof Format) {
            throw new CliException(\sprintf("unknown format '%s' please use [%s]", $name, self::FORMAT_LIST));
        }

        return $format;
    }

    private function formatOptions(ParsedArguments $args, Format $format, bool $colors, bool $unwrap): FormatOptions
    {
        return new FormatOptions(
            indent: $args->int('indent'),
            colors: $colors,
            unwrapScalar: $unwrap,
            prettyPrint: $args->bool('prettyPrint'),
            noDocSeparator: $args->bool('no-doc'),
            csvSeparator: $args->string('csv-separator'),
            csvAutoParse: Format::Tsv === $format ? $args->bool('tsv-auto-parse') : $args->bool('csv-auto-parse'),
            propertiesSeparator: $args->string('properties-separator'),
            propertiesArrayBrackets: $args->bool('properties-array-brackets'),
            xmlAttributePrefix: $args->string('xml-attribute-prefix'),
            xmlContentName: $args->string('xml-content-name'),
            xmlSkipProcInst: $args->bool('xml-skip-proc-inst'),
            xmlSkipDirectives: $args->bool('xml-skip-directives'),
            xmlKeepNamespace: $args->bool('xml-keep-namespace'),
            xmlRawToken: $args->bool('xml-raw-token'),
            xmlStrictMode: $args->bool('xml-strict-mode'),
            shellKeySeparator: $args->string('shell-key-separator'),
            luaUnquoted: $args->bool('lua-unquoted'),
            luaGlobals: $args->bool('lua-globals'),
            yamlFixMergeAnchorToSpec: $args->bool('yaml-fix-merge-anchor-to-spec'),
        );
    }

    /**
     * The inputs to read, and the content to append after the results (front matter `process`).
     *
     * @param list<string> $files
     * @param resource     $stdin
     *
     * @return array{list<FileInput>, string}
     *
     * @throws CliException
     */
    private function inputs(ParsedArguments $args, array $files, SourceDocuments $source, mixed $stdin): array
    {
        $mode = $args->string('front-matter');
        if ('' === $mode || [] === $files) {
            return [array_map(static fn (string $file): FileInput => new FileInput($file), $files), ''];
        }

        [$yaml, $rest] = new FrontMatterSplitter()->split($source->contents($files[0], $stdin));

        return [[new FileInput($files[0], $yaml)], 'process' === $mode ? $rest : ''];
    }
}
