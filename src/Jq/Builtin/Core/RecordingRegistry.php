<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Runtime\Builtin;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;

/**
 * A registry decorator that remembers the `name/arity` of every native registered through it, which is how
 * `builtins` knows the natives without the registry interface having to list them.
 *
 * @internal
 */
final class RecordingRegistry implements BuiltinRegistry
{
    /** @var list<string> */
    private array $signatures = [];

    public function __construct(private readonly BuiltinRegistry $inner)
    {
    }

    public function register(Builtin $builtin): void
    {
        $this->inner->register($builtin);
        $this->signatures[] = $builtin->name() . '/' . $builtin->arity();
    }

    public function addPrelude(string $source): void
    {
        $this->inner->addPrelude($source);
    }

    public function lookup(string $name, int $arity): ?Builtin
    {
        return $this->inner->lookup($name, $arity);
    }

    public function prelude(): string
    {
        return $this->inner->prelude();
    }

    /**
     * @return list<string>
     */
    public function signatures(): array
    {
        return $this->signatures;
    }
}
