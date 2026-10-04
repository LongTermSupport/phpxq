<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use InvalidArgumentException;
use LTS\PhpXq\Jq\Runtime\BuiltinInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DefaultBuiltinRegistryTest extends TestCase
{
    public function testLooksUpByNameAndArity(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $builtin  = $this->builtin('length', 0);
        $registry->register($builtin);

        self::assertSame($builtin, $registry->lookup('length', 0));
        self::assertNull($registry->lookup('length', 1));
        self::assertNull($registry->lookup('keys', 0));
    }

    public function testSameNameWithDifferentArityCoexists(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $one      = $this->builtin('range', 1);
        $two      = $this->builtin('range', 2);
        $registry->register($one);
        $registry->register($two);

        self::assertSame($one, $registry->lookup('range', 1));
        self::assertSame($two, $registry->lookup('range', 2));
    }

    public function testDuplicateRegistrationIsRejected(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $registry->register($this->builtin('length', 0));

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->builtin('length', 0));
    }

    public function testLazyLoaderRunsOnFirstLookupOfAnyOfItsNamesAndOnlyOnce(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $calls    = 0;
        $registry->registerLazy(['one/0', 'two/1'], function (BuiltinRegistryInterface $target) use (&$calls): void {
            ++$calls;
            $target->register($this->builtin('one', 0));
            $target->register($this->builtin('two', 1));
        });

        self::assertSame(0, $calls);
        self::assertNull($registry->lookup('one', 1), 'a name the loader does not claim is not loaded');
        self::assertSame(0, $calls);

        self::assertSame('two', $registry->lookup('two', 1)?->name());
        self::assertSame('one', $registry->lookup('one', 0)?->name());
        self::assertSame(1, $calls);
    }

    public function testLazyNameThatTheLoaderFailsToRegisterIsNull(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $registry->registerLazy(['ghost/0'], static function (): void {
        });

        self::assertNull($registry->lookup('ghost', 0));
        self::assertNull($registry->lookup('ghost', 0));
    }

    public function testPreludeSourcesAreConcatenatedInOrder(): void
    {
        $registry = new DefaultBuiltinRegistry();
        self::assertSame('', $registry->prelude());

        $registry->addPrelude('def a: 1;');
        $registry->addPrelude('def b: 2;');

        self::assertSame("def a: 1;\ndef b: 2;\n", $registry->prelude());
    }

    private function builtin(string $name, int $arity): BuiltinInterface
    {
        return new readonly class($name, $arity) implements BuiltinInterface {
            public function __construct(private string $name, private int $arity)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function arity(): int
            {
                return $this->arity;
            }
        };
    }
}
