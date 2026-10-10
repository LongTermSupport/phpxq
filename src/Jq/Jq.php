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
use LTS\PhpXq\Jq\Runtime\RefusingModuleLoader;
use LTS\PhpXq\Json\Codec\Utf8;
use LTS\PhpXq\Json\JsonDecoder;

/**
 * Runs a jq program over a PHP value and returns every output. The supported way to use jq from PHP code.
 *
 * Values are the ones {@see JsonDecoder} produces: null, bool, int, float, string, list
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
     * `$ENV` and `env` are empty, and `import` / `include` are compile errors, unless you opt in: both read
     * from the host, so leave them off when the program comes from outside your code.
     *
     * @param array<string, mixed> $variables    the `$name` variables the program can read, by name
     * @param bool                 $allowEnv     expose the process environment as `$ENV` and `env`
     * @param bool                 $allowModules allow `import` / `include` (searched where the program's `search`
     *                                           metadata says, else `~/.jq` and `$ORIGIN/../lib`)
     *
     * @return list<mixed>
     *
     * @throws JqCompileException the program does not parse or compile
     * @throws JqException        the program raised an error that nothing caught; `value` holds it
     */
    public static function run(string $program, mixed $input, array $variables = [], bool $allowEnv = false, bool $allowModules = false): array
    {
        // one fiber for the whole run: parsing and compiling recurse as deeply as the program nests
        return EvaluationStack::run(static function () use ($program, $input, $variables, $allowEnv, $allowModules): array {
            $parser   = new Parser(new Lexer());
            $modules  = $allowModules ? new FileModuleLoader([], $parser, new JsonDecoder()) : new RefusingModuleLoader();
            $compiler = new Compiler(StandardBuiltins::create(), $parser, $modules);
            $compiled = $compiler->compile($parser->parse(Utf8::sanitize($program)), ['ENV', '__prog_args', 'ARGS', ...array_map(strval(...), array_keys($variables))]);

            $outputs = [];

            try {
                $compiled->run(new EmbeddedContext($variables, $allowEnv), $input, static function (mixed $output) use (&$outputs): void {
                    $outputs[] = $output;
                });
            } catch (HaltException $haltException) {
                if (0 !== $haltException->exitCode || null !== $haltException->stderrText) {
                    throw new JqException($haltException->stderrText ?? \sprintf('halted with status %d', $haltException->exitCode), $haltException);
                }

                return $outputs;
            }

            return $outputs;
        });
    }
}
