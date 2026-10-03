<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

use LTS\PhpXq\Yaml\NodeStyleEnum;

/**
 * One scanner token. Line and column are 1-based and point at the first character of the token.
 */
final readonly class Token
{
    public function __construct(
        public TokenTypeEnum $type,
        public string $value = '',
        public int $line = 1,
        public int $column = 1,
        public NodeStyleEnum $style = NodeStyleEnum::Default,
    ) {
    }
}
