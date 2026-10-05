<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\StringDiscriminator;

interface OddSyntaxSource
{
    public function value(): string;
}

/**
 * Shapes the rule cannot judge; none of them may crash it.
 */
abstract class OddSyntaxNotReported implements OddSyntaxSource
{
    abstract public function nothing(): void;

    /**
     * @param list<string>         $names
     * @param array<string, mixed> $map
     */
    public function dynamicAndSpread(string $name, array $names, array $map, string $class): bool
    {
        $callable   = \in_array(...);
        [, $second] = [1, 2];
        $items      = [...$names, 'head', 'foot'];
        $x          = \in_array($name, [...$names, 'head'], true);
        $y          = \in_array($name, ...$names, true);
        $z          = \in_array(...$names);

        return 'head' === $this->{$name}
            || 'foot' === $this->{$name}
            || 'head' === $map[$name]
            || 'foot' === $map[$name]
            || 'head' === $class::$name
            || 'foot' === $class::$name
            || 'head' === $this->value()
            || 'foot' === $this->value()
            || 'head' === ${$name}
            || 'foot' === ${$name}
            || 'head' === $this->value()
            || 'foot' === $this->value()
            || $callable;
    }

    public function matchWithoutArmsAndSwitchWithoutCases(string $kind): int
    {
        switch ($kind) {
        }

        return match ($kind) {
            default => 1,
        };
    }

    public function matchArmsOnUnsupportedSubject(string $kind): int
    {
        return match (strtolower($kind)) {
            'head'  => 1,
            'foot'  => 2,
            default => 0,
        };
    }

    public function closureWithOneLiteral(): callable
    {
        return static fn (string $text): bool => 'head' === $text;
    }
}
