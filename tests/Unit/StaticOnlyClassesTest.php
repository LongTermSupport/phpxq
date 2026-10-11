<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit;

use LTS\PhpXq\Jq\Builtin\CoreBuiltins;
use LTS\PhpXq\Jq\Jq;
use LTS\PhpXq\Yq\Yq;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The classes that are only static members (the library entry points and a constant holder) cannot be
 * instantiated: a `new` on one would build an object that means nothing.
 *
 * @internal
 */
#[CoversClass(Jq::class)]
#[CoversClass(Yq::class)]
#[CoversClass(CoreBuiltins::class)]
final class StaticOnlyClassesTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('staticOnlyClasses')]
    public function testTheClassHasAPrivateConstructorAndCannotBeInstantiated(string $class): void
    {
        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor, $class . ' declares the guard constructor');
        self::assertTrue($constructor->isPrivate(), $class . ' keeps its constructor private');
        self::assertFalse($reflection->isInstantiable(), $class . ' cannot be instantiated from outside');

        $constructor->invoke($reflection->newInstanceWithoutConstructor());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function staticOnlyClasses(): iterable
    {
        yield 'Jq' => [Jq::class];

        yield 'Yq' => [Yq::class];

        yield 'CoreBuiltins' => [CoreBuiltins::class];
    }
}
