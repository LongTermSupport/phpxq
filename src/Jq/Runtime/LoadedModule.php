<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Jq\Ast\Program;

/**
 * A parsed library and the absolute path it was read from (the importer path for its own imports).
 *
 * @api
 */
final readonly class LoadedModule
{
    public function __construct(
        public Program $program,
        public string $path,
    ) {
    }
}
