<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\EmbeddedContext;
use LTS\PhpXq\Jq\Runtime\EmptyInputs;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EmbeddedContextTest extends TestCase
{
    public function testHasNoFilesNoLibrariesAndNoInputs(): void
    {
        $context = new EmbeddedContext();

        self::assertNull($context->inputFilename());
        self::assertSame([], $context->libraryPaths());
        self::assertInstanceOf(EmptyInputs::class, $context->inputs());
    }

    public function testGlobalsHoldTheVariablesTheEnvironmentAndTheArguments(): void
    {
        $globals = new EmbeddedContext(['x' => 1])->globals();

        self::assertSame(1, $globals['x']);
        self::assertInstanceOf(JsonObject::class, $globals['ENV']);
        self::assertInstanceOf(JsonObject::class, $globals['ARGS']);
        self::assertInstanceOf(JsonObject::class, $globals['__prog_args']);
        self::assertSame(['x' => 1], $globals['__prog_args']->toArray());
    }

    public function testDiagnosticsAreDiscardedAndTheClockMoves(): void
    {
        $context = new EmbeddedContext();
        $before  = $context->now();

        $context->debug('d');
        $context->writeStderr('e');

        self::assertGreaterThanOrEqual($before, $context->now());
    }
}
