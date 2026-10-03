<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonDecoderInterface;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonEncoderInterface;

/**
 * The jq command line: option parsing, input reading, program compilation and execution, output
 * formatting and exit codes. OWNER: CLI worker (all of src/Jq/Cli/ except the two FROZEN wiring files
 * CompilerFactoryInterface and DefaultCompilerFactory). Skeleton only: returns "not implemented".
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
     * @return int one of the {@see JqExitCode} values
     */
    public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        fwrite($stderr, "jq: not implemented\n");

        return JqExitCode::NOT_IMPLEMENTED;
    }
}
