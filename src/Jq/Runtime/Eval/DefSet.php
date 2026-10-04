<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * The top-level definitions visible in one source file (the main program, a library module or the prelude):
 * its own defs in order, included modules, aliased imports, data imports and the enclosing set (the prelude)
 * consulted last.
 *
 * @internal
 */
final class DefSet
{
    /** @var list<FuncInfo> */
    private array $functions = [];

    /** @var array<string, list<int>> positions in per name/arity */
    private array $index = [];

    /** @var list<DefSet> */
    private array $includes = [];

    /** @var array<string, list<DefSet>> */
    private array $aliases = [];

    /** @var array<string, list<mixed>> */
    private array $data = [];

    public function __construct(private readonly ?self $parent = null)
    {
    }

    public function add(FuncInfo $function): void
    {
        $this->index[$function->signature()][] = \count($this->functions);
        $this->functions[]                     = $function;
    }

    public function size(): int
    {
        return \count($this->functions);
    }

    /**
     * @return list<FuncInfo>
     */
    public function functions(): array
    {
        return $this->functions;
    }

    public function include(self $set): void
    {
        $this->includes[] = $set;
    }

    public function alias(string $name, self $set): void
    {
        $this->aliases[$name][] = $set;
    }

    public function addData(string $name, mixed ...$values): void
    {
        $this->data[$name] = array_values($values);
    }

    /**
     * @return ?list<mixed>
     */
    public function data(string $name): ?array
    {
        return $this->data[$name] ?? null;
    }

    /**
     * The definition of name/arity visible from a position: own defs before $limit (latest first), then the
     * included modules, then the enclosing set.
     */
    public function find(string $name, int $arity, int $limit): ?FuncInfo
    {
        $found = $this->findOwn($name, $arity, $limit);

        return $found ?? $this->parent?->find($name, $arity, \PHP_INT_MAX);
    }

    /**
     * A definition reached through an import alias: the latest import wins, within it the latest def.
     */
    public function findAliased(string $alias, string $name, int $arity): ?FuncInfo
    {
        foreach (array_reverse($this->aliases[$alias] ?? []) as $set) {
            $found = $set->findOwn($name, $arity, \PHP_INT_MAX);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private function findOwn(string $name, int $arity, int $limit): ?FuncInfo
    {
        foreach (array_reverse($this->index[$name . '/' . $arity] ?? []) as $position) {
            if ($position < $limit) {
                return $this->functions[$position];
            }
        }

        foreach (array_reverse($this->includes) as $set) {
            $found = $set->findOwn($name, $arity, \PHP_INT_MAX);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
