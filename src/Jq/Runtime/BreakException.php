<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use RuntimeException;

/**
 * Non-local exit used for `break $label`, and by native builtins (limit, first, isempty, ...) that stop a
 * generator early. $label is a token object created fresh each time a `label` is entered (or a builtin
 * starts a bounded run), so a catcher compares identity (`===`) and re-throws a foreign label. It is never a
 * jq error: `try` must not catch it.
 *
 * @internal
 */
final class BreakException extends RuntimeException
{
    public function __construct(public readonly object $label)
    {
        parent::__construct('break');
    }
}
