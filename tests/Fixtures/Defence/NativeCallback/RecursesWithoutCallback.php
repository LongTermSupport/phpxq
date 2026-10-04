<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\NativeCallback;

/**
 * Recursion that never crosses a native frame, and callbacks that never recurse.
 */
final class RecursesWithoutCallback
{
    /**
     * @param list<mixed> $left
     * @param list<mixed> $right
     */
    public function equals(array $left, array $right): bool
    {
        foreach ($left as $index => $item) {
            if (\is_array($item) ? !$this->equals($item, $right[$index]) : $item !== $right[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Not recursive itself: the callback calls a method that recurses, but the callback returns before the
     * recursion starts, so no native frame stays on the stack while it runs.
     *
     * @param list<list<mixed>> $groups
     *
     * @return list<bool>
     */
    public function eachGroup(array $groups): array
    {
        return array_map(fn (array $group): bool => $this->equals($group, $group), $groups);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function shout(array $names): array
    {
        return array_map(strtoupper(...), $names);
    }
}
