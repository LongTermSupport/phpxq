<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use InvalidArgumentException;
use LTS\PhpXq\Jq\Builtin\Core\Prelude;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Tests\Support\Jq\EagerBuiltins;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CoreBuiltinsTest extends TestCase
{
    public function testRegistersTheNativesAndThePrelude(): void
    {
        $registry = new DefaultBuiltinRegistry();
        EagerBuiltins::core($registry);

        self::assertInstanceOf(ValueBuiltinInterface::class, $registry->lookup('length', 0));
        self::assertInstanceOf(ValueBuiltinInterface::class, $registry->lookup('format', 1));
        self::assertInstanceOf(StreamBuiltinInterface::class, $registry->lookup('range', 3));
        self::assertInstanceOf(PathStreamBuiltinInterface::class, $registry->lookup('select', 1));
        self::assertNull($registry->lookup('length', 1));
        self::assertNull($registry->lookup('no_such_builtin', 0));
        self::assertStringContainsString('def walk(f):', $registry->prelude());
    }

    public function testRegisteringTwiceIsAnError(): void
    {
        $registry = new DefaultBuiltinRegistry();
        EagerBuiltins::core($registry);

        $this->expectException(InvalidArgumentException::class);

        EagerBuiltins::core($registry);
    }

    public function testNoNativeShadowsAPreludeDefinition(): void
    {
        $registry = new DefaultBuiltinRegistry();
        EagerBuiltins::core($registry);

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
