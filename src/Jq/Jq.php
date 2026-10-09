<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq;

use LTS\PhpXq\Jq\Builtin\StandardBuiltins;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\EmbeddedContext;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\HaltException;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\Codec\Utf8;
use LTS\PhpXq\Json\JsonDecoder;

/**
 * Runs a jq program over a PHP value and returns every output. The supported way to use jq from PHP code.
 *
 * Values are the ones {@see \LTS\PhpXq\Json\JsonDecoder} produces: null, bool, int, float, string, list
 * arrays and {@see \LTS\PhpXq\Json\JsonObject} for objects.
 *
 * @api
 */
final readonly class Jq
{
    private function __construct()
    {
    }

    /**
     * `halt` ends the run quietly and keeps the outputs produced so far; `halt_error` raises a
     * {@see JqException} carrying the text it would have written to standard error. `input` fails and
     * `inputs` is empty. `debug` and `stderr` output is discarded.
     *
     * @param array<string, mixed> $variables the `$name` variables the program can read, by name
     *
     * @return list<mixed>
     *
     * @throws JqCompileException the program does not parse or compile
     * @throws JqException        the program raised an error that nothing caught; `value` holds it
     */
    public static function run(string $program, mixed $input, array $variables = []): array
    {
        // one fiber for the whole run: parsing and compiling recurse as deeply as the program nests
        return EvaluationStack::run(static function () use ($program, $input, $variables): array {
            $parser   = new Parser(new Lexer());
            $compiler = new Compiler(StandardBuiltins::create(), $parser, new FileModuleLoader([], $parser, new JsonDecoder()));
            $compiled = $compiler->compile($parser->parse(Utf8::sanitize($program)), ['ENV', '__prog_args', 'ARGS', ...array_map(strval(...), array_keys($variables))]);

            $outputs = [];

            try {
                $compiled->run(new EmbeddedContext($variables), $input, static function (mixed $output) use (&$outputs): void {
                    $outputs[] = $output;
                });
            } catch (HaltException $halt) {
                if (0 !== $halt->exitCode || null !== $halt->stderrText) {
                    throw new JqException($halt->stderrText ?? \sprintf('halted with status %d', $halt->exitCode), $halt);
                }

                return $outputs;
            }

            return $outputs;
        });
    }
}
