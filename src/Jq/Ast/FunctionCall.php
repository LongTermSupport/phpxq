<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `name` or `name(arg; arg)`: a call to a user, prelude or native function, resolved by name/arity.
 * Namespaced calls keep the `::` in the name (`a::f`). The parser desugars `..` to recurse/0.
 * $line (1-based) is used for the compile-error message "f/0 is not defined at <top-level>, line N".
 *
 * @api
 */
final readonly class FunctionCall implements NodeInterface
{
    /**
     * @param list<NodeInterface> $args
     */
    public function __construct(
        public string $name,
        public array $args = [],
        public int $line = 1,
    ) {
    }

    public function arity(): int
    {
        return \count($this->args);
    }

    public function signature(): string
    {
        return $this->name . '/' . $this->arity();
    }
}
