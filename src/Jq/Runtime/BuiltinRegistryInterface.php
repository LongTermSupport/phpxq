<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * The set of native builtins plus the jq-source prelude that defines the rest. The compiler looks a call
 * up by name and arity: lexical `def`s first, then the prelude, then natives.
 *
 * @internal
 */
interface BuiltinRegistryInterface
{
    public function register(BuiltinInterface $builtin): void;

    public function lookup(string $name, int $arity): ?BuiltinInterface;

    public function prelude(): string;
}
