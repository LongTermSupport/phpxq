<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LogicException;
use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RunState::class)]
final class RunStateTest extends TestCase
{
    public function testContextIsUnavailableOutsideARun(): void
    {
        $this->expectException(LogicException::class);

        new RunState()->context();
    }

    public function testReservedGlobalsAreAvailableWithoutDeclaration(): void
    {
        $state = new RunState();

        $environment = $state->global('ENV');
        self::assertInstanceOf(JsonObject::class, $environment);
        self::assertSame($environment, $state->global('ENV'));
        self::assertEquals(JsonObject::fromPairs(getenv()), $environment);
        self::assertSame([], $state->global('__prog_args'));
        self::assertNull($state->global('undeclared'));
    }

    public function testEnterInstallsAndSnapshotRestores(): void
    {
        $state   = new RunState();
        $context = new StubContext(['a' => 1]);
        $state->enter($context, ['a' => 1]);
        $snapshot = $state->snapshot();

        $state->enter(null, []);
        self::assertNull($state->global('a'));

        $state->enter($snapshot['context'], $snapshot['globals']);
        self::assertSame($context, $state->context());
        self::assertSame(1, $state->global('a'));
    }
}
