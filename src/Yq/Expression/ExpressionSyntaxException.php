<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

use RuntimeException;

/**
 * A malformed yq expression. `offset` is the 0-based byte offset of the problem in the expression text.
 */
final class ExpressionSyntaxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $offset,
    ) {
        parent::__construct($message);
    }
}
