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
use LTS\PhpXq\Jq\Builtin\Core\RecordingRegistry;
use LTS\PhpXq\Jq\Builtin\Core\StringFunctions;
use LTS\PhpXq\Jq\Builtin\Core\TypeFunctions;
use LTS\PhpXq\Jq\Builtin\CoreBuiltins;
use LTS\PhpXq\Jq\Builtin\DateBuiltins;
use LTS\PhpXq\Jq\Builtin\RegexBuiltins;
use LTS\PhpXq\Jq\Builtin\StandardBuiltins;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BuiltinCatalog::class)]
final class BuiltinCatalogTest extends TestCase
{
    public function testEveryGroupListsExactlyWhatItRegisters(): void
    {
        $registrars = [
            'type'       => TypeFunctions::register(...),
            'math'       => MathFunctions::register(...),
            'string'     => StringFunctions::register(...),
            'format'     => FormatFunctions::register(...),
            'collection' => CollectionFunctions::register(...),
            'control'    => ControlFunctions::register(...),
            'path'       => PathFunctions::register(...),
            'io'         => static fn (BuiltinRegistryInterface $registry) => IoFunctions::register($registry, static fn (): array => []),
            'regex'      => static fn (BuiltinRegistryInterface $registry) => new RegexBuiltins()->registerInto($registry),
            'date'       => static fn (BuiltinRegistryInterface $registry) => new DateBuiltins()->registerInto($registry),
        ];

        foreach ($registrars as $group => $register) {
            $recording = new RecordingRegistry(new DefaultBuiltinRegistry());
            $register($recording);

            self::assertSame(BuiltinCatalog::GROUPS[$group], $recording->signatures(), 'group ' . $group);
        }
    }

    public function testNamesAreTheBuiltinsTheEagerRegistryReports(): void
    {
        $eager = new DefaultBuiltinRegistry();
        new CoreBuiltins()->registerInto($eager);

        $builtin = $eager->lookup('builtins', 0);
        self::assertInstanceOf(ValueBuiltinInterface::class, $builtin);
        self::assertSame($builtin->call(new StubContext(), null, []), BuiltinCatalog::names());
        self::assertContains('map_values/1', BuiltinCatalog::names());
        self::assertNotContains('_sort_by_impl/1', BuiltinCatalog::names());
    }

    public function testStandardRegistryResolvesEveryGroupAndTheSamePrelude(): void
    {
        $lazy  = StandardBuiltins::create();
        $eager = new DefaultBuiltinRegistry();
        new CoreBuiltins()->registerInto($eager);
        new RegexBuiltins()->registerInto($eager);
        new DateBuiltins()->registerInto($eager);

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
