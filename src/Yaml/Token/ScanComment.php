<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

/**
 * A comment found by the scanner, with the positions the comment-association rules need. `head` is a
 * comment block that precedes content, `line` a comment after a token on its line, `foot` a block that
 * trails earlier content. Exactly one of the three is non-empty.
 */
final readonly class ScanComment
{
    public function __construct(
        public int $scanIndex,
        public int $tokenIndex,
        public int $startIndex,
        public int $startLine,
        public int $startColumn,
        public int $endIndex,
        public int $endLine,
        public int $endColumn,
        public string $head = '',
        public string $line = '',
        public string $foot = '',
    ) {
    }
}
