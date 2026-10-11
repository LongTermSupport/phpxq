<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use LTS\PhpXq\Jq\Builtin\BuiltinCatalog;
use LTS\PhpXq\Jq\Builtin\Core\CollectionFunctions;
use LTS\PhpXq\Jq\Builtin\Core\ControlFunctions;
use LTS\PhpXq\Jq\Builtin\Core\FormatFunctions;
use LTS\PhpXq\Jq\Builtin\Core\IoFunctions;
use LTS\PhpXq\Jq\Builtin\Core\MathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\PathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\Prelude;
use LTS\PhpXq\Jq\Builtin\Core\StringFunctions;
use LTS\PhpXq\Jq\Builtin\Core\TypeFunctions;
use LTS\PhpXq\Jq\Builtin\DateBuiltins;
use LTS\PhpXq\Jq\Builtin\RegexBuiltins;
use LTS\PhpXq\Jq\Builtin\StandardBuiltins;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Tests\Support\Jq\EagerBuiltins;
use LTS\PhpXq\Tests\Support\Jq\RecordingRegistry;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BuiltinCatalog::class)]
final class BuiltinCatalogTest extends TestCase
{
    /**
     * @param callable(BuiltinRegistryInterface): void $register
     */
    #[DataProvider('provideGroupRegistrars')]
    public function testEveryGroupListsExactlyWhatItRegisters(string $group, callable $register): void
    {
        $recording = new RecordingRegistry(new DefaultBuiltinRegistry());
        $register($recording);

        self::assertSame(BuiltinCatalog::GROUPS[$group], $recording->signatures(), 'group ' . $group);
    }

    /**
     * @return iterable<string, array{string, callable(BuiltinRegistryInterface): void}>
     */
    public static function provideGroupRegistrars(): iterable
    {
        yield 'type' => ['type', TypeFunctions::register(...)];

        yield 'math' => ['math', MathFunctions::register(...)];

        yield 'string' => ['string', StringFunctions::register(...)];

        yield 'format' => ['format', FormatFunctions::register(...)];

        yield 'collection' => ['collection', CollectionFunctions::register(...)];

        yield 'control' => ['control', ControlFunctions::register(...)];

        yield 'path' => ['path', PathFunctions::register(...)];

        yield 'io' => ['io', static fn (BuiltinRegistryInterface $registry) => IoFunctions::register($registry, static fn (): array => [])];

        yield 'regex' => ['regex', static fn (BuiltinRegistryInterface $registry) => new RegexBuiltins()->registerNatives($registry)];

        yield 'date' => ['date', static fn (BuiltinRegistryInterface $registry) => new DateBuiltins()->registerNatives($registry)];
    }

    public function testNamesAreTheBuiltinsTheEagerRegistryReports(): void
    {
        $eager = new DefaultBuiltinRegistry();
        EagerBuiltins::core($eager);

        $builtin = $eager->lookup('builtins', 0);
        self::assertInstanceOf(ValueBuiltinInterface::class, $builtin);
        self::assertSame($builtin->call(new StubContext(), null), BuiltinCatalog::names());
        self::assertContains('map_values/1', BuiltinCatalog::names());
        self::assertNotContains('_sort_by_impl/1', BuiltinCatalog::names());
    }

    public function testStandardRegistryResolvesEveryGroupAndTheSamePrelude(): void
    {
        $lazy  = StandardBuiltins::create();
        $eager = new DefaultBuiltinRegistry();
        EagerBuiltins::core($eager);
        EagerBuiltins::regex($eager);
        EagerBuiltins::date($eager);

        foreach (BuiltinCatalog::GROUPS as $signatures) {
            foreach ($signatures as $signature) {
                [$name, $arity] = explode('/', $signature);
                $expected       = $eager->lookup($name, (int)$arity);
                self::assertNotNull($expected, $signature);
                self::assertInstanceOf($expected::class, $lazy->lookup($name, (int)$arity), $signature);
            }
        }

        self::assertSame($eager->prelude(), $lazy->prelude());
        self::assertStringContainsString(Prelude::SOURCE, $lazy->prelude());
    }

    public function testGroupsDoNotOverlap(): void
    {
        $all = array_merge(...array_values(BuiltinCatalog::GROUPS));

        self::assertSame(array_unique($all), $all);
    }

    public function testLoaderRegistersOneGroupOnce(): void
    {
        $registry = new DefaultBuiltinRegistry();
        BuiltinCatalog::registerLazily($registry);

        self::assertNull($registry->lookup('floor', 1));
        self::assertNotNull($registry->lookup('floor', 0));
        self::assertNotNull($registry->lookup('floor', 0));
    }
}
