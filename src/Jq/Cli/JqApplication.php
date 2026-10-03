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
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\Codec\JqColors;
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
     * @param list<string> $args   arguments after `jq` (flags, program, files), exactly as jq would receive them
     * @param resource     $stdin
     * @param resource     $stdout
     * @param resource     $stderr
     *
     * @return int one of the {@see JqExitCode} values, or the status a `halt_error` asked for
     */
    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        $console = new Console($stdout, $stderr);
        $status  = $this->execute($args, $stdin, $stdout, $console);
        $console->flush();

        return $status;
    }

    /**
     * @param list<string> $args
     * @param resource     $stdin
     * @param resource     $stdout
     */
    private function execute(array $args, mixed $stdin, mixed $stdout, Console $console): int
    {
        try {
            $options = new OptionParser($this->decoder)->parse($args);
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

        $program = $this->compile($source, $options, $console);
        if (!$program instanceof CompiledProgramInterface) {
            return $program;
        }

        return $this->runProgram($program, $options, $stdin, $stdout, $console);
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

            return $this->compilers->create($options->libraryPaths)->compile($ast, $options->globalNames());
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
                $console->err("\n" . $this->snippet(
                    $lines[(int)$matches[1] - 1] ?? '',
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

        $status = ProgramRunner::NO_OUTPUT;
        $last   = -1;

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

                if ($console->stdoutFailed()) {
                    break;
                }
            }
        }

        if ($console->stdoutFailed()) {
            $console->err("jq: error: writing output failed: Broken pipe\n");

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
