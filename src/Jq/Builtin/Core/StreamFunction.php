<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;

/**
 * A {@see StreamBuiltinInterface} backed by a closure: `(context, input, filters, emit) => void`.
 *
 * @internal
 */
final readonly class StreamFunction implements StreamBuiltinInterface
{
    /**
     * @param Closure(RuntimeContextInterface, mixed, list<FilterInterface>, Closure(mixed): void): void $function
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

    public function run(RuntimeContextInterface $context, mixed $input, array $args, Closure $emit): void
    {
        ($this->function)($context, $input, $args, $emit);
    }
}
