<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `try body catch handler`; `try body` and the postfix `body?` have a null handler (errors are
 * swallowed). Postfix `?` binds to the preceding postfix term only.
 *
 * @api
 */
final readonly class TryCatch implements Node
{
    public function __construct(
        public Node $body,
        public ?Node $handler,
    ) {
    }
}
