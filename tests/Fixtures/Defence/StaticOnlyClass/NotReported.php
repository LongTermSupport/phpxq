<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\StaticOnlyClass;

/**
 * The guard is present.
 */
final class GuardedStaticOnly
{
    private function __construct()
    {
    }

    public static function twice(int $value): int
    {
        return $value * 2;
    }
}

/**
 * A constructor of any visibility is the author's decision.
 */
final class PublicConstructor
{
    public static int $made = 0;

    public function __construct()
    {
        ++self::$made;
    }
}

/**
 * An instance method makes the class instantiable on purpose.
 */
final class InstanceMethod
{
    public static function make(): self
    {
        return new self();
    }

    public function value(): int
    {
        return 1;
    }
}

/**
 * An instance property does too.
 */
final class InstanceProperty
{
    public int $value = 1;

    public static function make(): self
    {
        return new self();
    }
}

/**
 * Abstract classes cannot be instantiated.
 */
abstract class AbstractStaticOnly
{
    public static function twice(int $value): int
    {
        return $value * 2;
    }
}

/**
 * Nothing to guard.
 */
final class NoMembers
{
}

/**
 * A trait can bring instance members in, so the class is not judged.
 */
trait InstanceTrait
{
    public function value(): int
    {
        return 1;
    }
}

final class UsesTrait
{
    use InstanceTrait;

    public static function twice(int $value): int
    {
        return $value * 2;
    }
}

/**
 * A parent can supply the constructor.
 */
final class ParentWithConstructor
{
    public function __construct()
    {
    }
}

final class ChildOfParent extends ParentWithConstructor
{
    public static function twice(int $value): int
    {
        return $value * 2;
    }
}

interface HasConstant
{
    public const int LIMIT = 1;
}

enum StaticOnlyEnum
{
    public static function first(): self
    {
        return self::One;
    }
    case One;
}

final class OwnsAnonymousClass
{
    private function __construct()
    {
    }

    public static function make(): object
    {
        return new class {
            public static function twice(int $value): int
            {
                return $value * 2;
            }
        };
    }
}
