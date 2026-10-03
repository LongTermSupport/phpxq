<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Builtin\Regex\NativeStream;
use LTS\PhpXq\Jq\Builtin\Regex\NativeValue;
use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NativeBuiltinsTest extends TestCase
{
    public function testNativeValueDelegatesToItsClosure(): void
    {
        $builtin = new NativeValue('twice', 1, static fn (mixed $input, array $args): mixed => [$input, $args[0]]);

        self::assertSame('twice', $builtin->name());
        self::assertSame(1, $builtin->arity());
        self::assertSame(['in', 'arg'], $builtin->call($this->context(), 'in', ['arg']));
    }

    public function testNativeValuePassesTheContext(): void
    {
        $context = $this->context();
        $builtin = new NativeValue('ctx', 0, static fn (mixed $input, array $args, RuntimeContext $given): mixed => $given);

        self::assertSame($context, $builtin->call($context, null, []));
    }

    public function testNativeStreamDelegatesToItsClosure(): void
    {
        $builtin = new NativeStream('both', 2, static function (mixed $input, array $args, Closure $emit): void {
            $emit($input);
            $emit(\count($args));
        });

        self::assertSame('both', $builtin->name());
        self::assertSame(2, $builtin->arity());

        $seen = [];
        $builtin->run($this->context(), 'x', [FakeFilter::yielding(1), FakeFilter::yielding(2)], static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame(['x', 2], $seen);
    }

    private function context(): RuntimeContext
    {
        return new class implements RuntimeContext {
            public function inputs(): InputProviderInterface
            {
                throw new JqException('no inputs');
            }

            public function globals(): array
            {
                return [];
            }

            public function inputFilename(): ?string
            {
                return null;
            }

            public function libraryPaths(): array
            {
                return [];
            }

            public function debug(mixed $value): void
            {
            }

            public function writeStderr(mixed $value): void
            {
            }

            public function now(): float
            {
                return 0.0;
            }
        };
    }
}
