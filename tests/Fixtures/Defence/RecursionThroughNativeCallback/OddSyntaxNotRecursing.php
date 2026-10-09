<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\RecursionThroughNativeCallback;

interface OddSyntaxContract
{
    public function walk(array $items): array;
}

trait OddSyntaxTrait
{
    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public function viaTrait(array $items): array
    {
        return array_map(static fn (mixed $item): mixed => $item, $items);
    }
}

/**
 * Call and argument shapes that are not a recursive callback handed to a native function, or that cannot be
 * followed; none of them may crash the rule.
 */
abstract class OddSyntaxNotRecursing implements OddSyntaxContract
{
    use OddSyntaxTrait;

    abstract public function nothing(): void;

    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public function recursiveButNotInACallback(array $items, string $name, string $class): array
    {
        $mapper   = array_map(...);
        $dynamic  = $name(...);
        $spread   = array_map(...[fn (mixed $item): mixed => $this->recursiveButNotInACallback([$item], $name, $class), $items]);
        $callable = array_map([$this, $name], $items);
        $static   = array_map($class::run(...), $items);
        $helper   = array_map($this->helper(...), $items);
        $nullsafe = array_map(static fn (?object $item): mixed => $item?->value, $items);

        return [$mapper, $dynamic, $spread, $callable, $static, $helper, $nullsafe, ...$this->recursiveButNotInACallback($items, $name, $class)];
    }

    private function helper(mixed $item): mixed
    {
        return $item;
    }
}
