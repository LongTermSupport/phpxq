<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

/**
 * One lexical token with its 0-based byte offset in the expression text. A raw string (one whose body holds
 * `\(`) has empty text and carries its parts instead: decoded literal text, and the tokens of each
 * interpolation, which end with an EndOfInput token and carry offsets in the same expression text.
 *
 * @internal
 */
final readonly class ExpressionToken
{
    /**
     * @param list<string|non-empty-list<ExpressionToken>> $parts
     */
    public function __construct(
        public ExpressionTokenKindEnum $kind,
        public string $text,
        public int $offset,
        public bool $raw = false,
        public array $parts = [],
    ) {
    }
}
