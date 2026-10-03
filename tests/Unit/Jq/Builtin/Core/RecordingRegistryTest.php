<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use InvalidArgumentException;
use LTS\PhpXq\Jq\Builtin\Core\RecordingRegistry;
use LTS\PhpXq\Jq\Builtin\Core\ValueFunction;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordingRegistryTest extends TestCase
{
    public function testRegistrationsReachTheInnerRegistryAndAreRecorded(): void
    {
        $inner     = new DefaultBuiltinRegistry();
        $recording = new RecordingRegistry($inner);
        $builtin   = new ValueFunction('f', 2, static fn (): mixed => 1);

        $recording->register($builtin);

        self::assertSame($builtin, $inner->lookup('f', 2));
        self::assertSame($builtin, $recording->lookup('f', 2));
        self::assertNull($recording->lookup('f', 1));
        self::assertSame(['f/2'], $recording->signatures());
    }

    public function testPreludeGoesToTheInnerRegistry(): void
    {
        $inner     = new DefaultBuiltinRegistry();
        $recording = new RecordingRegistry($inner);

        $recording->addPrelude('def a: 1;');

        self::assertSame($inner->prelude(), $recording->prelude());
        self::assertStringContainsString('def a: 1;', $recording->prelude());
        self::assertSame([], $recording->signatures());
    }

    public function testDuplicatesAreStillRejected(): void
    {
        $recording = new RecordingRegistry(new DefaultBuiltinRegistry());
        $recording->register(new ValueFunction('f', 0, static fn (): mixed => 1));

        $this->expectException(InvalidArgumentException::class);

        $recording->register(new ValueFunction('f', 0, static fn (): mixed => 2));
    }
}
