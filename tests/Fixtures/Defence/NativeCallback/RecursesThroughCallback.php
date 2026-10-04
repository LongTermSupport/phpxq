<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\NativeCallback;

/**
 * Every recursive method here hands the recursive call to a native function as a callback.
 */
final class RecursesThroughCallback
{
    /**
     * @param list<mixed> $left
     * @param list<mixed> $right
     */
    public function equals(array $left, array $right): bool
    {
        return array_all($left, fn (mixed $item, int $index): bool => \is_array($item) ? $this->equals($item, $right[$index]) : $item === $right[$index]);
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public function mapFirstClass(array $items): array
    {
        return array_map(self::mapFirstClass(...), $items);
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public function mutualA(array $items): array
    {
        return array_map(function (mixed $item): mixed {
            return $this->mutualB($item);
        }, $items);
    }

    /**
     * @return list<mixed>
     */
    public function mutualB(mixed $item): array
    {
        return $this->mutualA([$item]);
    }
}
