<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;

/**
 * A {@see StreamBuiltinInterface} backed by a closure `(input, emit, ...filters) => void`.
 */
final readonly class CallbackStreamBuiltin implements StreamBuiltinInterface
{
    /**
     * @param Closure(mixed, Closure, FilterInterface ...): void $callback
     */
    public function __construct(
        private string $name,
        private int $arity,
        private Closure $callback,
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
        ($this->callback)($input, $emit, ...$args);
    }
}
