<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;

/**
 * A {@see StreamBuiltinInterface} backed by a closure: `(context, input, emit, ...filters) => void`.
 *
 * @internal
 */
final readonly class StreamFunction implements StreamBuiltinInterface
{
    /**
     * @param Closure(RuntimeContextInterface, mixed, Closure(mixed): void, FilterInterface...): void $function
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

    public function run(RuntimeContextInterface $context, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        ($this->function)($context, $input, $emit, ...$args);
    }
}
