<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

/**
 * One lexical token with its 0-based byte offset in the expression text.
 */
final readonly class ExpressionToken
{
    public function __construct(
        public ExpressionTokenKindEnum $kind,
        public string $text,
        public int $offset,
        public bool $raw = false,
    ) {
    }
}
