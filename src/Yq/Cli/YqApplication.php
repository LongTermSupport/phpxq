<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LogicException;
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
final class YqApplication implements YqApplicationInterface
{
    /** The pinned reference release this implementation follows (see tests/Conformance/Yq/fixtures/NOTICE.md). */
    public const string REFERENCE_VERSION = 'v4.54.1';

    private readonly ArgumentParser $parser;

    private readonly EvaluateCommand $evaluate;

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

    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        $first = $args[0] ?? '';
        if ('__complete' === $first || '__completeNoDesc' === $first) {
            return new CompleteCommand()->run(\array_slice($args, 1), '__complete' === $first, $stdout, $stderr);
        }

        try {
            $parsed = $this->parser->parse($args);
        } catch (UsageException $e) {
            fwrite($stderr, 'Error: ' . $e->getMessage() . "\n" . HelpText::usage('') . "\n");

            return self::EXIT_ERROR;
        }

        try {
            return $this->dispatch($parsed, $stdin, $stdout, $stderr);
        } catch (UsageException $e) {
            fwrite($stderr, 'Error: ' . $e->getMessage() . "\n" . HelpText::usage($parsed->command) . "\n");
        } catch (CliException|EvaluationException|ExpressionSyntaxException|FormatException|YamlSyntaxException|LogicException $e) {
            fwrite($stderr, 'Error: ' . $e->getMessage() . "\n");
        }

        return self::EXIT_ERROR;
    }

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     *
     * @throws CliException
     */
    private function dispatch(ParsedArguments $parsed, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        if ($parsed->bool('version')) {
            fwrite($stdout, 'yq (https://github.com/mikefarah/yq/) version ' . self::REFERENCE_VERSION . "\n");

            return self::EXIT_OK;
        }

        if ($parsed->bool('help')) {
            fwrite($stdout, HelpText::forCommand($parsed->command));

            return self::EXIT_OK;
        }

        return match ($parsed->command) {
            'help'       => $this->help($parsed, $stdout),
            'completion' => $this->completion($parsed, $stdout),
            'eval-all', 'ea' => $this->evaluate->run($parsed, true, $stdin, $stdout),
            default      => $this->evaluate->run($parsed, false, $stdin, $stdout),
        };
    }

    /**
     * @param resource $stdout
     */
    private function help(ParsedArguments $parsed, mixed $stdout): int
    {
        fwrite($stdout, HelpText::forCommand($parsed->positionals[0] ?? ''));

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
