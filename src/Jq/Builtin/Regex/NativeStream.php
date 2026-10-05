<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;

/**
 * A {@see StreamBuiltinInterface} defined by a closure.
 *
 * @internal
 */
final readonly class NativeStream implements StreamBuiltinInterface
{
    /**
     * @param Closure(mixed, Closure(mixed): void, FilterInterface...): void $function receives the input, the emitter and the closure parameters
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
        ($this->function)($input, $emit, ...$args);
    }
}
