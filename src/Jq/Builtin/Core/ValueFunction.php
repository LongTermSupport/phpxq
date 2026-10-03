<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Jq\Runtime\ValueBuiltin;

/**
 * A {@see ValueBuiltin} backed by a closure: `(context, input, args) => output`.
 *
 * @internal
 */
final readonly class ValueFunction implements ValueBuiltin
{
    /**
     * @param Closure(RuntimeContext, mixed, list<mixed>): mixed $function
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
        return ($this->function)($context, $input, $args);
    }
}
