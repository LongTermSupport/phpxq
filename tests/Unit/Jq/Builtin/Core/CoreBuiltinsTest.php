<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use InvalidArgumentException;
use LTS\PhpXq\Jq\Builtin\Core\Prelude;
use LTS\PhpXq\Jq\Builtin\CoreBuiltins;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltin;
use LTS\PhpXq\Jq\Runtime\StreamBuiltin;
use LTS\PhpXq\Jq\Runtime\ValueBuiltin;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CoreBuiltinsTest extends TestCase
{
    public function testRegistersTheNativesAndThePrelude(): void
    {
        $registry = new DefaultBuiltinRegistry();
        new CoreBuiltins()->registerInto($registry);

        self::assertInstanceOf(ValueBuiltin::class, $registry->lookup('length', 0));
        self::assertInstanceOf(ValueBuiltin::class, $registry->lookup('format', 1));
        self::assertInstanceOf(StreamBuiltin::class, $registry->lookup('range', 3));
        self::assertInstanceOf(PathStreamBuiltin::class, $registry->lookup('select', 1));
        self::assertNull($registry->lookup('length', 1));
        self::assertNull($registry->lookup('no_such_builtin', 0));
        self::assertStringContainsString('def walk(f):', $registry->prelude());
    }

    public function testRegisteringTwiceIsAnError(): void
    {
        $registry = new DefaultBuiltinRegistry();
        new CoreBuiltins()->registerInto($registry);

        $this->expectException(InvalidArgumentException::class);

        new CoreBuiltins()->registerInto($registry);
    }

    public function testNoNativeShadowsAPreludeDefinition(): void
    {
        $registry = new DefaultBuiltinRegistry();
        new CoreBuiltins()->registerInto($registry);

        foreach (Prelude::signatures() as $signature) {
            [$name, $arity] = explode('/', $signature);
            self::assertNull($registry->lookup($name, (int)$arity), $signature . ' is both native and defined in the prelude');
        }
    }

    public function testEveryDefinitionOfThePreludeIsFound(): void
    {
        $signatures = Prelude::signatures();

        foreach (['halt_error/0', 'map_values/1', 'INDEX/2', 'JOIN/4', 'IN/1', 'nth/2', 'truncate_stream/1', 'indices/1', 'combinations/1'] as $expected) {
            self::assertContains($expected, $signatures);
        }

        self::assertSame(array_unique($signatures), $signatures);
        self::assertCount(substr_count(Prelude::SOURCE, "\ndef ") + 1, $signatures);
    }
}
