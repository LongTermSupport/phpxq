<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Jq;

use LTS\PhpXq\Jq\Runtime\BuiltinInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;

/**
 * A registry that forwards to another one and remembers the `name/arity` of every native registered
 * through it, so a test can compare what a group registers with what the catalog says it does.
 */
final class RecordingRegistry implements BuiltinRegistryInterface
{
    /** @var list<string> */
    private array $signatures = [];

    public function __construct(private readonly BuiltinRegistryInterface $inner)
    {
    }

    public function register(BuiltinInterface $builtin): void
    {
        $this->inner->register($builtin);
        $this->signatures[] = $builtin->name() . '/' . $builtin->arity();
    }

    public function lookup(string $name, int $arity): ?BuiltinInterface
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
