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

    /**
     * Append jq source (a sequence of `def`s with no main body) to the prelude. Provider preludes are
     * concatenated in registration order and parsed once by the compiler.
     */
    public function addPrelude(string $source): void;

    public function lookup(string $name, int $arity): ?BuiltinInterface;

    public function prelude(): string;
}
