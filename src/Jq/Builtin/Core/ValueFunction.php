<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;

/**
 * A {@see ValueBuiltinInterface} backed by a closure: `(context, input, args) => output`.
 *
 * @internal
 */
final readonly class ValueFunction implements ValueBuiltinInterface
{
    /**
     * @param Closure(RuntimeContextInterface, mixed, list<mixed>): mixed $function
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
        return ($this->function)($context, $input, $args);
    }
}
