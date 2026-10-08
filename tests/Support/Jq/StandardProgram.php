<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Jq;

use Closure;
use LTS\PhpXq\Jq\Builtin\StandardBuiltins;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;

/**
 * Compiles a jq program against the full builtin library, as the command line does, and runs it on values.
 */
final class StandardProgram
{
    private function __construct()
    {
    }

    /**
     * @return Closure(mixed): list<string> runs the program on one input value and gives the compact JSON of
     *                                      every output
     *
     * @throws JqCompileException
     */
    public static function compile(string $program): Closure
    {
        $decoder  = new JsonDecoder();
        $encoder  = new JsonEncoder();
        $parser   = new Parser(new Lexer());
        $compiled = new Compiler(StandardBuiltins::create(), $parser, new FileModuleLoader([], $parser, $decoder))
            ->compile($parser->parse($program))
        ;

        return static function (mixed $input) use ($compiled, $encoder): array {
            $outputs = [];
            $compiled->run(new StubContext(), $input, static function (mixed $output) use ($encoder, &$outputs): void {
                $outputs[] = $encoder->encode($output, EncodeOptions::compact());
            });

            return $outputs;
        };
    }

    /**
     * @return list<string> the compact JSON of every output of the program on the JSON input
     *
     * @throws JqCompileException
     */
    public static function outputs(string $program, string $input = 'null'): array
    {
        return self::compile($program)(new JsonDecoder()->decodeOne($input));
    }
}
