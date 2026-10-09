<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\RecursionThroughNativeCallback;

/**
 * Recursion through a native callback written with named arguments, a static closure, and inside an enum.
 */
final class RecursesInOddSyntax
{
    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public function namedArguments(array $items): array
    {
        return array_map(array: $items, callback: fn (mixed $item): mixed => $this->namedArguments([$item]));
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public static function staticClosure(array $items): array
    {
        return array_map(static function (mixed $item): mixed {
            return self::staticClosure([$item]);
        }, $items);
    }
}

enum RecursiveEnum: string
{
    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    public function walk(array $items): array
    {
        return array_map(fn (mixed $item): mixed => $this->walk([$item]), $items);
    }
    case One = 'one';
}
