<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `try body catch handler`; `try body` and the postfix `body?` have a null handler (errors are
 * swallowed). Postfix `?` binds to the preceding postfix term only.
 *
 * @internal
 */
final readonly class TryCatch implements NodeInterface
{
    public function __construct(
        public NodeInterface $body,
        public ?NodeInterface $handler,
    ) {
    }
}
