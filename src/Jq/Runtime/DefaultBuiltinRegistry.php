<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use InvalidArgumentException;

/**
 * Array backed {@see BuiltinRegistryInterface}. Registering the same name/arity twice is a programming error.
 *
 * @api
 */
final class DefaultBuiltinRegistry implements BuiltinRegistryInterface
{
    /** @var array<string, BuiltinInterface> */
    private array $builtins = [];

    private string $prelude = '';

    public function register(BuiltinInterface $builtin): void
    {
        $key = $builtin->name() . '/' . $builtin->arity();
        if (isset($this->builtins[$key])) {
            throw new InvalidArgumentException('Builtin already registered: ' . $key);
        }

        $this->builtins[$key] = $builtin;
    }

    public function addPrelude(string $source): void
    {
        $this->prelude .= $source . "\n";
    }

    public function lookup(string $name, int $arity): ?BuiltinInterface
    {
        return $this->builtins[$name . '/' . $arity] ?? null;
    }

    public function prelude(): string
    {
        return $this->prelude;
    }
}
