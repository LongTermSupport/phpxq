<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LogicException;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Emitter\YamlEmitterInterface;
use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Expression\ExpressionParser;
use LTS\PhpXq\Yq\Expression\ExpressionParserInterface;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use LTS\PhpXq\Yq\Runtime\EvaluatorInterface;

/**
 * The yq command line: `yq [flags] [expression] [file...]` (the default `eval`), `yq eval`, `yq eval-all`,
 * `yq completion <shell>`, `yq help [command]` and `yq --version`.
 *
 * Every failure prints `Error: <message>` on standard error and returns 1, as the reference does.
 */
final readonly class YqApplication implements YqApplicationInterface
{
    /** The pinned reference release this implementation follows (see tests/Conformance/Yq/fixtures/NOTICE.md). */
    public const string REFERENCE_VERSION = 'v4.54.1';

    /** What every error message written to stderr starts with. */
    private const string ERROR_PREFIX = 'Error: ';

    /** The line after a malformed expression's message, naming the 0-based byte offset the parser stopped at. */
    private const string SYNTAX_OFFSET_NOTE = "  at offset %d of the expression\n";

    private ArgumentParser $parser;

    private EvaluateCommand $evaluate;

    public function __construct(
        YamlParserInterface $yamlParser = new YamlParser(),
        YamlEmitterInterface $emitter = new YamlEmitter(),
        ExpressionParserInterface $expressions = new ExpressionParser(),
        EvaluatorInterface $evaluator = new Evaluator(),
        FormatRegistryInterface $formats = new FormatRegistry(),
    ) {
        $this->parser   = new ArgumentParser();
        $this->evaluate = new EvaluateCommand($yamlParser, $emitter, $expressions, $evaluator, $formats);
    }

    public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        // The cycle collector re-scans the huge, cycle-free node tree every few thousand allocations; a run
        // is short-lived, so it is switched off for its duration (benchmarks yq:identity-medium and
        // yq:group-medium, results-yq.md optimisation 4).
        $collector = gc_enabled();
        gc_disable();

        try {
            return $this->runCommand($stdin, $stdout, $stderr, ...$args);
        } finally {
            if ($collector) {
                gc_enable();
            }
        }
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    private function runCommand(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        $first = [] === $args ? '' : $args[0];
        $named = CommandEnum::tryFrom($first);
        if ($named instanceof CommandEnum && $named->isCompletionRequest()) {
            return new CompleteCommand()->run(CommandEnum::Complete === $named, $stdout, $stderr, ...\array_slice($args, 1));
        }

        try {
            $parsed = $this->parser->parse(...$args);
        } catch (UsageException $usageException) {
            fwrite($stderr, self::ERROR_PREFIX . $usageException->getMessage() . "\n" . HelpText::usage('') . "\n");

            return self::EXIT_ERROR;
        }

        try {
            // the evaluator walks the expression recursively, and a chain such as `.a.a.a...` is as deep as it
            // is long: under a coverage driver every PHP call also takes native stack, so it runs on the
            // evaluation fiber's large stack rather than the process stack
            return EvaluationStack::run(fn (): int => $this->dispatch($parsed, $stdin, $stdout));
        } catch (UsageException $e) {
            fwrite($stderr, self::ERROR_PREFIX . $e->getMessage() . "\n" . HelpText::usage($parsed->command) . "\n");
        } catch (ExpressionSyntaxException $e) {
            fwrite($stderr, self::ERROR_PREFIX . $e->getMessage() . "\n" . \sprintf(self::SYNTAX_OFFSET_NOTE, $e->offset));
        } catch (CliException|EvaluationException|FormatException|YamlSyntaxException|LogicException $e) {
            if ($e instanceof CliException && CliException::BROKEN_PIPE === $e->getCode()) {
                return CliException::BROKEN_PIPE_EXIT;
            }

            fwrite($stderr, self::ERROR_PREFIX . $e->getMessage() . "\n");
        }

        return self::EXIT_ERROR;
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     *
     * @throws CliException
     * @throws EvaluationException
     * @throws ExpressionSyntaxException
     * @throws FormatException
     * @throws LogicException            a collaborator that is not implemented
     * @throws UsageException
     * @throws YamlSyntaxException
     */
    private function dispatch(ParsedArguments $parsed, mixed $stdin, mixed $stdout): int
    {
        if ($parsed->bool('version')) {
            fwrite($stdout, 'yq (https://github.com/mikefarah/yq/) version ' . self::REFERENCE_VERSION . "\n");

            return self::EXIT_OK;
        }

        if ($parsed->bool('help')) {
            fwrite($stdout, HelpText::forCommand($parsed->command));

            return self::EXIT_OK;
        }

        return match (CommandEnum::tryFrom($parsed->command)?->canonical()) {
            CommandEnum::Help       => $this->help($parsed, $stdout),
            CommandEnum::Completion => $this->completion($parsed, $stdout),
            CommandEnum::EvalAll    => $this->evaluate->run($parsed, true, $stdin, $stdout),
            default                 => $this->evaluate->run($parsed, false, $stdin, $stdout),
        };
    }

    /**
     * @param resource $stdout
     */
    private function help(ParsedArguments $parsed, mixed $stdout): int
    {
        fwrite($stdout, HelpText::forCommand([] === $parsed->positionals ? '' : $parsed->positionals[0]));

        return self::EXIT_OK;
    }

    /**
     * @param resource $stdout
     *
     * @throws CliException
     */
    private function completion(ParsedArguments $parsed, mixed $stdout): int
    {
        $count = \count($parsed->positionals);
        if (1 !== $count) {
            throw new UsageException(\sprintf('accepts 1 arg(s), received %d', $count));
        }

        $shell = $parsed->positionals[0];
        if (!\in_array($shell, CompletionScripts::SHELLS, true)) {
            throw new UsageException(\sprintf('unknown command "%s" for "yq completion"', $shell));
        }

        fwrite($stdout, CompletionScripts::forShell($shell));

        return self::EXIT_OK;
    }
}
