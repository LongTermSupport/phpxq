<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
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
     * @param Closure(mixed, list<\LTS\PhpXq\Jq\Runtime\FilterInterface>, Closure(mixed): void): void $function receives the input, the closure parameters and the emitter
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
        ($this->function)($input, $args, $emit);
    }
}
