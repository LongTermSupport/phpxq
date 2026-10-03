<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `import "path" as name;`, `import "path" as $name;` (data) or `include "path";`.
 *
 * $alias is the name without the leading `$` or null for include. $metadata is the optional constant
 * object that follows the path (for example {"search": "./"}), already evaluated to the value model.
 *
 * @api
 */
final readonly class ImportDirective
{
    public function __construct(
        public string $path,
        public ?string $alias,
        public ImportKindEnum $kind,
        public mixed $metadata = null,
    ) {
    }
}
