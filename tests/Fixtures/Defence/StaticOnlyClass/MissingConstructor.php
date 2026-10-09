<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\StaticOnlyClass;

/**
 * Static methods only, no constructor: callable as `new StaticMethodsOnly()`.
 */
final class StaticMethodsOnly
{
    public static function twice(int $value): int
    {
        return $value * 2;
    }
}

/**
 * Constants only.
 */
final class ConstantsOnly
{
    public const string NAME = 'name';
}

/**
 * A static property, a constant and a static method, with a non-final class.
 */
class MixedStaticMembers
{
    public const int LIMIT = 3;

    public static int $count = 0;

    public static function bump(): int
    {
        return ++self::$count;
    }
}
