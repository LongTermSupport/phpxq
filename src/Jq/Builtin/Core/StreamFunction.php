<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\Filter;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Jq\Runtime\StreamBuiltin;

/**
 * A {@see StreamBuiltin} backed by a closure: `(context, input, filters, emit) => void`.
 *
 * @internal
 */
final readonly class StreamFunction implements StreamBuiltin
{
    /**
     * @param Closure(RuntimeContext, mixed, list<Filter>, Closure(mixed): void): void $function
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

    public function run(RuntimeContext $context, mixed $input, array $args, Closure $emit): void
    {
        ($this->function)($context, $input, $args, $emit);
    }
}
