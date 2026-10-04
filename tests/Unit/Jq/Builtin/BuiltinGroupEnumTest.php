<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use LTS\PhpXq\Jq\Builtin\BuiltinCatalog;
use LTS\PhpXq\Jq\Builtin\BuiltinGroupEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuiltinGroupEnum::class)]
final class BuiltinGroupEnumTest extends TestCase
{
    #[DataProvider('catalogKeys')]
    public function testEveryGroupOfTheCatalogIsACase(string $group): void
    {
        self::assertInstanceOf(BuiltinGroupEnum::class, BuiltinGroupEnum::tryFrom($group));
    }

    #[DataProvider('catalogGroups')]
    public function testEveryCaseIsAGroupOfTheCatalog(string $group): void
    {
        self::assertArrayHasKey($group, BuiltinCatalog::GROUPS);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogKeys(): iterable
    {
        foreach (array_keys(BuiltinCatalog::GROUPS) as $group) {
            yield $group => [$group];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function catalogGroups(): iterable
    {
        foreach (BuiltinGroupEnum::cases() as $case) {
            yield $case->value => [$case->value];
        }
    }

    #[DataProvider('unknownGroups')]
    public function testAnUnknownGroupResolvesToNull(string $name): void
    {
        self::assertNull(BuiltinGroupEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownGroups(): iterable
    {
        yield 'not a group' => ['nope'];
    }
}
