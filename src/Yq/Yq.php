<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq;

use LogicException;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Cli\CliException;
use LTS\PhpXq\Yq\Cli\DocumentRegistry;
use LTS\PhpXq\Yq\Cli\FileInput;
use LTS\PhpXq\Yq\Cli\HeaderModeEnum;
use LTS\PhpXq\Yq\Cli\ResultPrinter;
use LTS\PhpXq\Yq\Cli\SourceDocuments;
use LTS\PhpXq\Yq\Expression\ExpressionParser;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use LTS\PhpXq\Yq\Runtime\EvaluationContext;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use LTS\PhpXq\Yq\Runtime\RuntimeServices;
use LTS\PhpXq\Yq\Runtime\SecurityOptions;
use RuntimeException;

/**
 * Runs a yq expression over a document held in a string and returns what the command line would print.
 * The supported way to use yq from PHP code.
 *
 * @api
 */
final readonly class Yq
{
    private function __construct()
    {
    }

    /**
     * Each document of the input is evaluated on its own, as `yq eval` does, and the results are written
     * in the output format: YAML documents separated by `---`, a JSON value per result, and so on. Comments
     * and styles that the expression leaves alone are kept. The `env` and file operators are refused unless
     * allowed; the `system` operator is never available.
     *
     * @return string the output text; empty when the expression matched nothing
     *
     * @throws EvaluationException       the expression failed on this document, or used a refused operator
     * @throws ExpressionSyntaxException the expression does not parse
     * @throws FormatException           the input is malformed in its format, or a result cannot be written in the output format
     * @throws YamlSyntaxException       the YAML input is malformed
     */
    public static function evaluate(
        string $expression,
        string $document,
        FormatEnum $input = FormatEnum::Yaml,
        FormatEnum $output = FormatEnum::Yaml,
        int $indent = 2,
        bool $allowEnv = false,
        bool $allowFiles = false,
    ): string {
        // one fiber for the whole run: parsing and evaluating recurse as deeply as the expression nests
        return EvaluationStack::run(static fn (): string => self::render($expression, $document, $input, $output, $indent, $allowEnv, $allowFiles));
    }

    private static function render(string $expression, string $document, FormatEnum $input, FormatEnum $output, int $indent, bool $allowEnv, bool $allowFiles): string
    {
        $yamlParser  = new YamlParser();
        $formats     = new FormatRegistry();
        $expressions = new ExpressionParser();
        $evaluator   = new Evaluator();
        $services    = new RuntimeServices($expressions, $yamlParser, $formats, new SecurityOptions(false, !$allowEnv, !$allowFiles));
        $unwrap      = FormatEnum::Json !== $output;
        $registry    = new DocumentRegistry();
        $program     = $expressions->parse('' === $expression ? '.' : $expression);
        $options     = new FormatOptions(indent: $indent, unwrapScalar: $unwrap);

        $sink = fopen('php://memory', 'w+b');
        if (false === $sink) {
            throw new RuntimeException('cannot open a memory stream');
        }

        $printer   = new ResultPrinter($sink, $output, new EmitOptions($indent, false, $unwrap), $options, new YamlEmitter(), $formats, $registry, false);
        $documents = new SourceDocuments($yamlParser, $formats, $registry)->read(
            [new FileInput('input', $document)],
            $sink, // standard input is never read: the one input carries its content
            $input,
            new FormatOptions(indent: $indent),
            HeaderModeEnum::None,
            FormatEnum::Yaml === $output,
        );

        try {
            foreach ($documents as $candidate) {
                $printer->print(...$evaluator->evaluate($program, new EvaluationContext([$candidate], $services)));
            }
        } catch (CliException $cliException) {
            $cause = $cliException->getPrevious();

            // every CliException the reader or printer raises here wraps a YAML or format error; one that does
            // not is a defect in this class, not bad input
            if ($cause instanceof YamlSyntaxException || $cause instanceof FormatException) {
                throw $cause;
            }

            throw new LogicException('unexpected command-line error: ' . $cliException->getMessage(), 0, $cliException);
        }

        $printer->finish('');
        rewind($sink);

        $text = stream_get_contents($sink);
        if (false === $text) {
            throw new RuntimeException('cannot read back the output stream');
        }

        return $text;
    }
}
