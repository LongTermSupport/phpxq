<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Jq\Runtime\CompilerInterface;

/**
 * Builds a compiler once the CLI knows the `-L` library paths. Exists so tests can inject a fake engine.
 *
 * @api
 */
interface CompilerFactoryInterface
{
    public function create(string ...$libraryPaths): CompilerInterface;
}
