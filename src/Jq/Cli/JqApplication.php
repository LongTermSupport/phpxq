<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Cli\Options\CliActionEnum;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Jq\Cli\Options\OptionParser;
use LTS\PhpXq\Jq\Cli\Options\UsageException;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\CompiledProgramInterface;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\Codec\JqColors;
use LTS\PhpXq\Json\Codec\Utf8;
use LTS\PhpXq\Json\ColorScheme;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonEncoderInterface;
use LTS\PhpXq\Json\JsonObject;
use RuntimeException;

/**
 * The jq command line: option parsing, input reading, program compilation and execution, output
 * formatting and exit codes (0 ok, 1 or 4 with `-e`, 2 usage or unreadable input, 3 compile error,
 * 5 runtime error or invalid input, or the status requested by `halt_error`).
 *
 * @api
 */
final readonly class JqApplication
{
    /**
     * Inputs between two manual runs of the cycle collector.
     */
    private const int GC_INTERVAL = 4096;

    public function __construct(
        public ParserInterface $parser,
        public CompilerFactoryInterface $compilers,
        public JsonDecoderInterface $decoder,
        public JsonEncoderInterface $encoder,
    ) {
    }

    /**
     * The production object graph.
     */
    public static function create(): self
    {
        $decoder = new JsonDecoder();
        $parser  = new Parser(new Lexer());

        return new self($parser, new DefaultCompilerFactory($parser, $decoder), $decoder, new JsonEncoder());
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     * @param string   ...$args arguments after `jq` (flags, program, files), exactly as jq would receive them
     *
     * @return int one of the {@see JqExitCode} values, or the status a `halt_error` asked for
     */
    public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        // The cycle collector is switched off for the run: jq values are trees, so what it would find is only
        // the evaluator's closure cycles, collected below every GC_INTERVAL inputs. Its automatic runs cost
        // 20% of identity-large and group-large (bench, results.md of Plan 00007).
        $collectorWasOn = gc_enabled();
        gc_disable();

        try {
            $console = new Console($stdout, $stderr);
            $status  = $this->execute($stdin, $stdout, $console, ...$args);
            $console->flush();
        } finally {
            if ($collectorWasOn) {
                gc_enable();
            }
        }

        return $status;
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    private function execute(mixed $stdin, mixed $stdout, Console $console, string ...$args): int
    {
        try {
            $options = new OptionParser($this->decoder)->parse(...$args);
        } catch (UsageException $usageException) {
            $console->err($usageException->getMessage() . "\n" . ($usageException->showShortUsage ? UsageText::short() : UsageText::hint()));

            return JqExitCode::USAGE;
        }

        if (CliActionEnum::Help === $options->action) {
            $console->out(UsageText::full());

            return JqExitCode::OK;
        }

        if (CliActionEnum::Version === $options->action) {
            $console->out(UsageText::version());

            return JqExitCode::OK;
        }

        if (CliActionEnum::BuildConfiguration === $options->action) {
            $console->out(UsageText::buildConfiguration());

            return JqExitCode::OK;
        }

        $source = $this->programSource($options, $stdin, $stdout, $console);
        if (!\is_string($source)) {
            return $source;
        }

        // like jq, which reads the program as a jq string: invalid UTF-8 in its literals becomes U+FFFD
        $source = Utf8::sanitize($source);

        // one fiber for the whole run: parsing and compiling recurse as deeply as the program nests, and
        // creating a fiber per input costs more than a small program does
        return EvaluationStack::run(function () use ($source, $options, $stdin, $stdout, $console): int {
            $program = $this->compile($source, $options, $console);
            if (!$program instanceof CompiledProgramInterface) {
                return $program;
            }

            return $this->runProgram($program, $options, $stdin, $stdout, $console);
        });
    }

    /**
     * The program text, or the exit status to stop with.
     *
     * @param resource $stdin
     * @param resource $stdout
     */
    private function programSource(CliOptions $options, mixed $stdin, mixed $stdout, Console $console): string|int
    {
        $program = $options->program;
        if (null === $program) {
            // like jq: with no program, filtering a pipe means "."; only a bare terminal gets the usage
            if ($options->fromFile || (stream_isatty($stdin) && stream_isatty($stdout))) {
                $console->err(UsageText::short());

                return JqExitCode::USAGE;
            }

            return '.';
        }

        if (!$options->fromFile) {
            return $program;
        }

        try {
            return FileReader::read($program);
        } catch (RuntimeException $runtimeException) {
            $console->err('jq: error: ' . $runtimeException->getMessage() . "\n");

            return JqExitCode::USAGE;
        }
    }

    private function compile(string $source, CliOptions $options, Console $console): CompiledProgramInterface|int
    {
        try {
            if ($options->fromFile && str_contains($source, "\0")) {
                throw new JqCompileException('Program file contains NUL bytes');
            }

            $home = getenv('HOME');
            $ast  = new ProgramLoader($this->parser, false === $home ? null : $home)->load($source);

            if ($options->debugDumpDisasm) {
                $console->out(implode("\n", ProgramDump::lines($ast)) . "\n");
            }

            return $this->compilers->create(...$options->libraryPaths)->compile($ast, $options->globalNames());
        } catch (JqCompileException $jqCompileException) {
            $this->reportCompileError($jqCompileException->getMessage(), $source, $console);

            return JqExitCode::COMPILE_ERROR;
        }
    }

    private function reportCompileError(string $message, string $source, Console $console): void
    {
        $entries = explode("\njq: error: ", $message);
        $lines   = explode("\n", $source);
        foreach ($entries as $entry) {
            $console->err('jq: error: ' . $entry);
            if (1 === preg_match('/ at <top-level>, line (\d+), column (\d+):$/', $entry, $matches)) {
                $lineIndex = (int)$matches[1] - 1;
                $console->err("\n" . $this->snippet(
                    \array_key_exists($lineIndex, $lines) ? $lines[$lineIndex] : '',
                    (int)$matches[2],
                    str_starts_with($entry, 'Possibly unterminated'),
                ));
            }

            $console->err("\n");
        }

        $count = \count($entries);
        $console->err(\sprintf("jq: %d compile error%s\n", $count, 1 === $count ? '' : 's'));
    }

    /**
     * The offending source line, indented, with carets under the token that starts at $column, or
     * under the rest of the line when $toEndOfLine (a construct that spans several tokens).
     */
    private function snippet(string $line, int $column, bool $toEndOfLine = false): string
    {
        $line  = rtrim($line, "\r");
        $width = 1;
        if ($toEndOfLine && $column >= 1 && $column <= \strlen($line)) {
            $width = \strlen(rtrim(substr($line, $column - 1)));
        } elseif ($column >= 1 && $column <= \strlen($line) && 1 === preg_match('/^[A-Za-z0-9_$@]+/', substr($line, $column - 1), $word)) {
            $width = \strlen($word[0]);
        }

        return '    ' . $line . "\n    " . str_repeat(' ', max(0, $column - 1)) . str_repeat('^', $width);
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    private function runProgram(CompiledProgramInterface $program, CliOptions $options, mixed $stdin, mixed $stdout, Console $console): int
    {
        $scheme  = $this->colorScheme($options, $stdout, $console);
        $source  = new InputSource(
            $options->files,
            $stdin,
            $this->decoder,
            $options,
            static function (string $message) use ($console): void {
                $console->err($message);
            },
        );
        $context = new CliRuntimeContext($source, $this->globals($options), $options->libraryPaths, $console, $this->encoder);
        $runner  = new ProgramRunner($program, $context, $source, $console, $this->encoder, $options, $options->encodeOptions($scheme));

        $status    = ProgramRunner::NO_OUTPUT;
        $last      = -1;
        $processed = 0;

        if ($options->nullInput) {
            $status = $runner->process(null);
            if ($status <= 0 && ProgramRunner::NO_OUTPUT !== $status) {
                $last = ProgramRunner::NULL_KIND !== $status ? 1 : 0;
            }
        } else {
            while (!$runner->halted && ($item = $source->fetch()) instanceof InputItem) {
                if ($item->isError()) {
                    if (!$item->fatal) {
                        $console->err('jq: ignoring parse error: ' . $item->error . "\n");

                        continue;
                    }

                    $console->err('jq: parse error: ' . $item->error . "\n");
                    $status = ProgramRunner::ERROR;

                    break;
                }

                $status = $runner->process($item->value);
                if ($status <= 0 && ProgramRunner::NO_OUTPUT !== $status) {
                    $last = ProgramRunner::NULL_KIND !== $status ? 1 : 0;
                }

                if (0 === ++$processed % self::GC_INTERVAL) {
                    gc_collect_cycles();
                }

                if ($console->stdoutFailed()) {
                    break;
                }
            }
        }

        $console->flush();
        if ($console->stdoutFailed()) {
            // jq is ended by SIGPIPE when the reader goes away (`jq . big | head`), without a word
            if ('Broken pipe' === $console->failureReason()) {
                return JqExitCode::BROKEN_PIPE;
            }

            $console->err('jq: error: writing output failed: ' . $console->failureReason() . "\n");

            return JqExitCode::USAGE;
        }

        if ($source->hadUnreadableFile()) {
            $status = JqExitCode::USAGE;
        }

        if ($options->exitStatus) {
            if (ProgramRunner::NO_OUTPUT !== $status) {
                return abs($status);
            }

            return match ($last) {
                -1      => JqExitCode::NO_OUTPUT,
                0       => JqExitCode::LAST_OUTPUT_FALSY,
                default => JqExitCode::OK,
            };
        }

        return max($status, 0);
    }

    /**
     * @param resource $stdout
     */
    private function colorScheme(CliOptions $options, mixed $stdout, Console $console): ?ColorScheme
    {
        $enabled = $options->color ?? (stream_isatty($stdout) && '' === (string)getenv('NO_COLOR'));
        if (!$enabled) {
            return null;
        }

        $spec = getenv('JQ_COLORS');
        if (false === $spec) {
            return ColorScheme::default();
        }

        $scheme = JqColors::parse($spec);
        if (!$scheme instanceof ColorScheme) {
            $console->err("Failed to set \$JQ_COLORS\n");

            return ColorScheme::default();
        }

        return $scheme;
    }

    /**
     * @return array<string, mixed>
     */
    private function globals(CliOptions $options): array
    {
        $named = JsonObject::fromPairs($options->named);

        return [
            ...$options->named,
            'ENV'         => JsonObject::fromPairs(getenv()),
            '__prog_args' => $named,
            'ARGS'        => JsonObject::fromPairs(['positional' => $options->positional, 'named' => $named]),
        ];
    }
}
