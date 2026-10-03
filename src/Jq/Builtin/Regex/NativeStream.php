<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Jq\Runtime\StreamBuiltin;

/**
 * A {@see StreamBuiltin} defined by a closure.
 *
 * @internal
 */
final readonly class NativeStream implements StreamBuiltin
{
    /**
     * @param Closure(mixed, list<\LTS\PhpXq\Jq\Runtime\Filter>, Closure(mixed): void): void $function receives the input, the closure parameters and the emitter
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
        ($this->function)($input, $args, $emit);
    }
}
