<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;

/**
 * A {@see ValueBuiltinInterface} backed by a closure `(input, ...args) => value`.
 */
final readonly class CallbackValueBuiltin implements ValueBuiltinInterface
{
    /**
     * @param Closure(mixed, mixed, mixed): mixed $callback
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

    public function call(RuntimeContextInterface $context, mixed $input, array $args): mixed
    {
        return ($this->callback)($input, ...$args);
    }
}
