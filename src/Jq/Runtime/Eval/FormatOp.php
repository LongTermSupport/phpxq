<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * A bare `@name`: the input converted with the named format.
 *
 * @internal
 */
final class FormatOp extends AbstractSingleOp
{
    /**
     * @param Closure(mixed): string $format
     */
    public function __construct(private readonly Closure $format)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        return ($this->format)($input);
    }
}
