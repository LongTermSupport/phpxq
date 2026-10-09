<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use Closure;
use InvalidArgumentException;

/**
 * Array backed {@see BuiltinRegistryInterface}. Registering the same name/arity twice is a programming error.
 *
 * @internal
 */
final class DefaultBuiltinRegistry implements BuiltinRegistryInterface
{
    /** @var array<string, BuiltinInterface> */
    private array $builtins = [];

    /** @var array<string, Closure(BuiltinRegistryInterface): void> loaders of natives not registered yet, by `name/arity` */
    private array $lazy = [];

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

    /**
     * Declare that $loader registers every one of $signatures (`name/arity`), and call it the first time one
     * of them is looked up. Keeps rarely used builtin classes from being loaded at start-up.
     *
     * @param Closure(BuiltinRegistryInterface): void $loader receives this registry
     */
    public function registerLazy(Closure $loader, string ...$signatures): void
    {
        foreach ($signatures as $signature) {
            $this->lazy[$signature] = $loader;
        }
    }

    public function lookup(string $name, int $arity): ?BuiltinInterface
    {
        $key = $name . '/' . $arity;
        if (isset($this->builtins[$key])) {
            return $this->builtins[$key];
        }

        $loader = $this->lazy[$key] ?? null;
        if (!$loader instanceof Closure) {
            return null;
        }

        $this->lazy = array_filter($this->lazy, static fn (Closure $pending): bool => $pending !== $loader);
        $loader($this);

        return $this->builtins[$key] ?? null;
    }

    public function prelude(): string
    {
        return $this->prelude;
    }
}
