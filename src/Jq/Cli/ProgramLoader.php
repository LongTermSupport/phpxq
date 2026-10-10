<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\JqCompileException;

/**
 * Turns program text into the AST the compiler gets: parses it, rejects a program that only defines
 * functions, and puts the definitions of the user's `~/.jq` file (when it is a file) in front of it.
 *
 * @internal
 */
final readonly class ProgramLoader
{
    public function __construct(
        private ParserInterface $parser,
        private ?string $homeDirectory,
    ) {
    }

    /**
     * @throws JqCompileException
     */
    public function load(string $source): Program
    {
        $program = $this->parser->parse($source);
        if (!$program->body instanceof \LTS\PhpXq\Jq\Ast\NodeInterface && ([] !== $program->defs || [] !== $program->imports)) {
            throw new JqCompileException('Top-level program not given (try ".")');
        }

        $home = $this->homeProgram();
        if (!$home instanceof Program) {
            return $program;
        }

        return new Program(
            [...$home->imports, ...$program->imports],
            $program->module,
            [...$home->defs, ...$program->defs],
            $program->body,
        );
    }

    /**
     * @throws JqCompileException
     */
    private function homeProgram(): ?Program
    {
        if (null === $this->homeDirectory || '' === $this->homeDirectory) {
            return null;
        }

        $file = rtrim($this->homeDirectory, '/') . '/.jq';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        return $this->parser->parse(FileReader::read($file));
    }
}
