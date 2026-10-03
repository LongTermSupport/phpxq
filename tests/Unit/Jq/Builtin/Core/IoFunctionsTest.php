<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Runtime\HaltException;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\ClosureFilter;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\FakeContext;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class IoFunctionsTest extends TestCase
{
    public function testInputReadsTheNextValue(): void
    {
        $context = new FakeContext([1, 2]);

        self::assertSame([1], Harness::stream('input', null, [], $context));
    }

    public function testInputWithoutMoreInputsFails(): void
    {
        self::assertSame('No more inputs', Harness::streamError('input', null, [], new FakeContext()));
    }

    public function testInputsDrainsTheRemainingValues(): void
    {
        self::assertSame([1, 2, 3], Harness::stream('inputs', null, [], new FakeContext([1, 2, 3])));
        self::assertSame([], Harness::stream('inputs', null, [], new FakeContext()));
    }

    public function testDebugReportsAndPassesTheInputOn(): void
    {
        $context = new FakeContext();

        self::assertSame([1], Harness::stream('debug', 1, [], $context));
        self::assertSame([1], $context->debugged);
    }

    public function testDebugWithAMessage(): void
    {
        $context = new FakeContext();

        self::assertSame([5], Harness::stream('debug', 5, [ClosureFilter::constants('a', 'b')], $context));
        self::assertSame(['a', 'b'], $context->debugged);
    }

    public function testStderrWritesAndPassesTheInputOn(): void
    {
        $context = new FakeContext();

        self::assertSame(['x'], Harness::stream('stderr', 'x', [], $context));
        self::assertSame(['x'], $context->stderr);
    }

    public function testInputFilename(): void
    {
        self::assertSame('in.json', Harness::call('input_filename', null, [], new FakeContext(filename: 'in.json')));
        self::assertNull(Harness::call('input_filename', null, [], new FakeContext()));
    }

    public function testInputLineNumberIsZeroWithoutAPosition(): void
    {
        self::assertSame(0, Harness::call('input_line_number', null));
    }

    public function testInputLineNumberReportsThePositionOfTheInputs(): void
    {
        self::assertSame(7, Harness::call('input_line_number', null, [], new FakeContext(line: 7)));
    }

    public function testHaltStopsWithStatusZero(): void
    {
        try {
            Harness::call('halt', null);
            self::fail('halt did not halt');
        } catch (HaltException $haltException) {
            self::assertSame(0, $haltException->exitCode);
            self::assertNull($haltException->stderrText);
        }
    }

    public function testHaltErrorWritesStringsAsIs(): void
    {
        try {
            Harness::call('halt_error', 'bye', [3]);
            self::fail('halt_error did not halt');
        } catch (HaltException $haltException) {
            self::assertSame(3, $haltException->exitCode);
            self::assertSame('bye', $haltException->stderrText);
        }
    }

    public function testHaltErrorWritesOtherValuesAsJsonWithANewline(): void
    {
        try {
            Harness::call('halt_error', new JsonObject(['a' => 'xyz']), [1]);
            self::fail('halt_error did not halt');
        } catch (HaltException $haltException) {
            self::assertSame(1, $haltException->exitCode);
            self::assertSame("{\"a\":\"xyz\"}\n", $haltException->stderrText);
        }
    }

    public function testHaltErrorWritesNothingForNull(): void
    {
        try {
            Harness::call('halt_error', null, [1]);
            self::fail('halt_error did not halt');
        } catch (HaltException $haltException) {
            self::assertSame(1, $haltException->exitCode);
            self::assertNull($haltException->stderrText);
        }
    }

    public function testHaltErrorNeedsANumericStatus(): void
    {
        self::assertSame('halt_error/1: number required', Harness::error('halt_error', 'x', ['a']));
    }

    public function testEnvPrefersTheContextEnvironment(): void
    {
        $env = new JsonObject(['PAGER' => 'less']);

        self::assertSame($env, Harness::call('env', null, [], new FakeContext(globals: ['ENV' => $env])));
    }

    public function testEnvFallsBackToTheProcessEnvironment(): void
    {
        $env = Harness::call('env', null);

        self::assertInstanceOf(JsonObject::class, $env);
        self::assertSame(getenv('PATH'), $env->get('PATH'));
    }

    public function testGetSearchList(): void
    {
        self::assertSame(['/a', '/b'], Harness::call('get_search_list', null, [], new FakeContext(libraryPaths: ['/a', '/b'])));
    }

    public function testBuiltinsListsNamesAndArities(): void
    {
        $builtins = Harness::call('builtins', null);

        self::assertIsArray($builtins);
        foreach (['length/0', 'map/1', 'range/3', 'to_entries/0', 'walk/1', 'IN/2', 'splits/2', 'strftime/1'] as $expected) {
            self::assertContains($expected, $builtins);
        }

        foreach ($builtins as $name) {
            self::assertIsString($name);
            self::assertStringNotContainsString('_', substr($name, 0, 1));
            self::assertMatchesRegularExpression('#^[A-Za-z_@][A-Za-z_0-9]*/[0-4]$#', $name);
        }

        $seen = [];
        foreach ($builtins as $name) {
            self::assertIsString($name);
            self::assertArrayNotHasKey($name, $seen, $name . ' is listed twice');
            $seen[$name] = true;
        }
    }
}
