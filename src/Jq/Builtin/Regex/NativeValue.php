<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Jq\Runtime\ValueBuiltin;

/**
 * A {@see ValueBuiltin} defined by a closure, so a provider can declare its natives without one class each.
 *
 * @internal
 */
final readonly class NativeValue implements ValueBuiltin
{
    /**
     * @param Closure(mixed, list<mixed>, RuntimeContext): mixed $function receives the input, the argument values and the context
     */
    public function __construct(
        private string $name,
        private int $arity,
        private Closure $function,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function arity(): int
    {
        return $this->arity;
    }

    public function call(RuntimeContext $context, mixed $input, array $args): mixed
    {
        return ($this->function)($input, $args, $context);
    }
}
