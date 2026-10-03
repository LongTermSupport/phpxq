<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `$__loc__`: evaluates to {"file": $file, "line": $line}.
 *
 * @api
 */
final readonly class Location implements Node
{
    public function __construct(
        public string $file,
        public int $line,
    ) {
    }
}
