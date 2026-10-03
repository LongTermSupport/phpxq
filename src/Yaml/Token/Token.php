<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

use LTS\PhpXq\Yaml\NodeStyle;

/**
 * One scanner token. Line and column are 1-based and point at the first character of the token.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value = '',
        public int $line = 1,
        public int $column = 1,
        public NodeStyle $style = NodeStyle::Default,
    ) {
    }
}
