<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;

/**
 * A {@see StreamBuiltinInterface} backed by a closure `(input, filters, emit) => void`.
 */
final readonly class CallbackStreamBuiltin implements StreamBuiltinInterface
{
    /**
     * @param Closure(mixed, list<\LTS\PhpXq\Jq\Runtime\FilterInterface>, Closure): void $callback
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

    public function run(RuntimeContextInterface $context, mixed $input, array $args, Closure $emit): void
    {
        ($this->callback)($input, $args, $emit);
    }
}
