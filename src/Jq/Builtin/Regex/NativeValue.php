<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;

/**
 * A {@see ValueBuiltinInterface} defined by a closure, so a provider can declare its natives without one class each.
 *
 * @internal
 */
final readonly class NativeValue implements ValueBuiltinInterface
{
    /**
     * @param Closure(mixed, list<mixed>, RuntimeContextInterface): mixed $function receives the input, the argument values and the context
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

    public function call(RuntimeContextInterface $context, mixed $input, array $args): mixed
    {
        return ($this->function)($input, $args, $context);
    }
}
