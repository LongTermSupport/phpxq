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
        public ?self $parent,
        public ScopeKindEnum $kind,
        public string $name,
        public int $arity = 0,
        public ?FuncInfo $function = null,
    ) {
    }

    public static function variable(?self $parent, string $name): self
    {
        return new self($parent, ScopeKindEnum::Variable, $name);
    }

    public static function param(?self $parent, string $name): self
    {
        return new self($parent, ScopeKindEnum::Param, $name);
    }

    public static function label(?self $parent, string $name): self
    {
        return new self($parent, ScopeKindEnum::Label, $name);
    }

    public static function func(?self $parent, FuncInfo $function): self
    {
        return new self($parent, ScopeKindEnum::Func, $function->definition->name, $function->definition->arity(), $function);
    }

    /**
     * Entries to skip to reach the nearest variable called $name, or null.
     */
    public function depthOfVariable(string $name): ?int
    {
        return $this->depthOf(ScopeKindEnum::Variable, $name);
    }

    public function depthOfLabel(string $name): ?int
    {
        return $this->depthOf(ScopeKindEnum::Label, $name);
    }

    private function depthOf(ScopeKindEnum $kind, string $name): ?int
    {
        $depth = 0;
        for ($scope = $this; $scope instanceof self; $scope = $scope->parent) {
            if ($scope->kind === $kind && $scope->name === $name) {
                return $depth;
            }

            ++$depth;
        }

        return null;
    }
}
