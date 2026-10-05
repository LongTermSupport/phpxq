<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use RuntimeException;
use Throwable;

/**
 * A jq runtime error: `error(v)` or a builtin failure. Carries the jq value that `try ... catch` binds.
 *
 * Builtins and the evaluator signal ordinary failures with {@see self::fromMessage()}, whose value is
 * the message string, so `catch .` sees exactly the text jq would. A non-string value (`error({a:1})`)
 * is carried as is; the CLI layer renders it as `jq: error (at <file>) (not a string): {...}`.
 *
 * @api
 */
final class JqException extends RuntimeException
{
    public function __construct(
        public readonly mixed $value,
        ?Throwable $previous = null,
    ) {
        parent::__construct(\is_string($value) ? $value : 'jq error (not a string)', 0, $previous);
    }

    public static function fromMessage(string $message): self
    {
        return new self($message);
    }
}
