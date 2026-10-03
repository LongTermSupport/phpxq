<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * The compile-time mirror of the run-time {@see Env} chain: one entry per binding, innermost first.
 *
 * @internal
 */
final readonly class Scope
{
    public function __construct(
        public ?Scope $parent,
        public ScopeKind $kind,
        public string $name,
        public int $arity = 0,
        public ?FuncInfo $function = null,
    ) {
    }

    public static function variable(?Scope $parent, string $name): self
    {
        return new self($parent, ScopeKind::Variable, $name);
    }

    public static function param(?Scope $parent, string $name): self
    {
        return new self($parent, ScopeKind::Param, $name);
    }

    public static function label(?Scope $parent, string $name): self
    {
        return new self($parent, ScopeKind::Label, $name);
    }

    public static function func(?Scope $parent, FuncInfo $function): self
    {
        return new self($parent, ScopeKind::Func, $function->definition->name, $function->definition->arity(), $function);
    }

    /**
     * Entries to skip to reach the nearest variable called $name, or null.
     */
    public function depthOfVariable(string $name): ?int
    {
        return $this->depthOf(ScopeKind::Variable, $name);
    }

    public function depthOfLabel(string $name): ?int
    {
        return $this->depthOf(ScopeKind::Label, $name);
    }

    private function depthOf(ScopeKind $kind, string $name): ?int
    {
        $depth = 0;
        for ($scope = $this; null !== $scope; $scope = $scope->parent) {
            if ($scope->kind === $kind && $scope->name === $name) {
                return $depth;
            }

            ++$depth;
        }

        return null;
    }
}
