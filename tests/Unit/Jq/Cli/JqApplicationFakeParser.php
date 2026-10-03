<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\JqCompileException;

/**
 * Parses three fixed sources: `SYNTAX` fails, `DEFS` is only a definition, anything else is the
 * identity program. Every parsed source is remembered.
 *
 * @internal
 */
final class JqApplicationFakeParser implements ParserInterface
{
    /** @var list<string> */
    public array $sources = [];

    public function parse(string $source): Program
    {
        $this->sources[] = $source;

        if (str_contains($source, 'SYNTAX')) {
            throw new JqCompileException('syntax error, unexpected INVALID_CHARACTER (Unix shell quoting issues?) at <top-level>, line 1, column 1:');
        }

        if (str_contains($source, 'catch ]')) {
            throw new JqCompileException(
                "syntax error, unexpected catch, expecting end or '|' or ',' at <top-level>, line 5, column 3:\n"
                . "jq: error: Possibly unterminated 'if' statement at <top-level>, line 2, column 7:\n"
                . "jq: error: Possibly unterminated 'try' statement at <top-level>, line 2, column 3:",
            );
        }

        if ("if\n" === $source) {
            throw new JqCompileException('syntax error, unexpected end of file at <top-level>, line 1, column 3:');
        }

        if ('DEFS' === $source) {
            return new Program([], null, [new FuncDef('f', [], new Identity())], null);
        }

        if (str_starts_with($source, 'def home')) {
            return new Program([], null, [new FuncDef('home', [], new Identity())], null);
        }

        return new Program([], null, [], new Identity());
    }
}
