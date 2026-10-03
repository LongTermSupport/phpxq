<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Jq\Runtime\StreamBuiltin;

/**
 * A {@see StreamBuiltin} backed by a closure `(input, filters, emit) => void`.
 */
final readonly class CallbackStreamBuiltin implements StreamBuiltin
{
    /**
     * @param Closure(mixed, list<\LTS\PhpXq\Jq\Runtime\Filter>, Closure): void $callback
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

    public function run(RuntimeContext $context, mixed $input, array $args, Closure $emit): void
    {
        ($this->callback)($input, $args, $emit);
    }
}
