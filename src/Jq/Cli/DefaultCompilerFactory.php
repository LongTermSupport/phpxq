<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Builtin\StandardBuiltins;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\CompilerInterface;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Json\JsonDecoderInterface;

/**
 * The production wiring: standard builtins, file module loader. FROZEN: edited only by the orchestrator.
 *
 * @internal
 */
final readonly class DefaultCompilerFactory implements CompilerFactoryInterface
{
    public function __construct(
        private ParserInterface $parser,
        private JsonDecoderInterface $decoder,
    ) {
    }

    public function create(string ...$libraryPaths): CompilerInterface
    {
        return new Compiler(
            StandardBuiltins::create(),
            $this->parser,
            new FileModuleLoader(array_values($libraryPaths), $this->parser, $this->decoder),
        );
    }
}
