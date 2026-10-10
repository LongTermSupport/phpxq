<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `break $name`. (`Break` is a reserved word in PHP, hence the name.) An unknown label is the compile
 * error "$*label-name is not defined".
 *
 * @internal
 */
final readonly class BreakOut implements NodeInterface
{
    public function __construct(
        public string $label,
        public int $line = 1,
    ) {
    }
}
