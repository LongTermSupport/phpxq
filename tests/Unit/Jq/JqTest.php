<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq;

use LTS\PhpXq\Jq\Jq;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqTest extends TestCase
{
    public function testRunsAProgramOverADecodedValueAndCollectsEveryOutput(): void
    {
        $input = new JsonDecoder()->decodeOne('{"items":[{"name":"a","n":1},{"name":"b","n":2},{"name":"c","n":3}]}');

        self::assertSame(['b', 'c'], Jq::run('.items[] | select(.n > 1) | .name', $input));
    }

    public function testAProgramWithNoOutputGivesAnEmptyList(): void
    {
        self::assertSame([], Jq::run('empty', null));
    }

    public function testObjectsComeBackAsJsonObjects(): void
    {
        $results = Jq::run('{a: .}', 1);

        self::assertCount(1, $results);
        self::assertInstanceOf(JsonObject::class, $results[0]);
        self::assertSame('{"a":1}', new JsonEncoder()->encode($results[0], EncodeOptions::compact()));
        self::assertSame(['a' => 1], $results[0]->toArray());
    }

    public function testNamedVariablesAreVisibleToTheProgram(): void
    {
        self::assertSame([42], Jq::run('$answer', null, ['answer' => 42]));
    }

    public function testTheEnvironmentIsHiddenByDefault(): void
    {
        putenv('PHPXQ_JQ_TEST=hello');

        try {
            self::assertSame([0, 0, null, null], Jq::run('($ENV | length), (env | length), $ENV.PHPXQ_JQ_TEST, env.PHPXQ_JQ_TEST', null));
        } finally {
            putenv('PHPXQ_JQ_TEST');
        }
    }

    public function testTheEnvironmentIsAvailableWhenAllowed(): void
    {
        putenv('PHPXQ_JQ_TEST=hello');

        try {
            self::assertSame(['hello', 'hello'], Jq::run('$ENV.PHPXQ_JQ_TEST, env.PHPXQ_JQ_TEST', null, allowEnv: true));
        } finally {
            putenv('PHPXQ_JQ_TEST');
        }
    }

    public function testImportsAreRefusedByDefaultEvenWithASearchPath(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/modules are disabled/');

        Jq::run('import "composer" as $c {search: "' . \dirname(__DIR__, 3) . '"}; $c', null);
    }

    public function testIncludesAreRefusedByDefault(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/modules are disabled/');

        Jq::run('include "anything"; .', null);
    }

    public function testModulesCanBeAllowed(): void
    {
        $directory = sys_get_temp_dir() . '/phpxq-jq-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/m.jq', 'def twice: . * 2;');

        try {
            self::assertSame([6], Jq::run('include "m" {search: "' . $directory . '"}; 3 | twice', null, allowModules: true));
        } finally {
            unlink($directory . '/m.jq');
            rmdir($directory);
        }
    }

    public function testHaltEndsTheRunKeepingTheOutputSoFar(): void
    {
        self::assertSame([1], Jq::run('1, halt, 2', null));
    }

    public function testHaltErrorRaisesAJqExceptionWithItsMessage(): void
    {
        try {
            Jq::run('1, ("bye\n" | halt_error), 2', null);
            self::fail('expected a JqException');
        } catch (JqException $jqException) {
            self::assertSame("bye\n", $jqException->value);
        }
    }

    public function testHaltWithAStatusRaisesAJqException(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessageMatches('/status 3/');

        Jq::run('null | halt_error(3)', null);
    }

    public function testAVeryLongProgramDoesNotOverflowTheNativeStack(): void
    {
        self::assertSame([20001], Jq::run('1' . str_repeat(' + 1', 20000), null));
    }

    public function testInvalidUtf8InTheProgramBecomesTheReplacementCharacter(): void
    {
        self::assertSame(["\u{FFFD}"], Jq::run("\"\xFF\"", null));
    }

    public function testInputsAreEmpty(): void
    {
        self::assertSame([[]], Jq::run('[inputs]', null));
    }

    public function testASyntaxErrorIsACompileException(): void
    {
        $this->expectException(JqCompileException::class);

        Jq::run('.items[', null);
    }

    public function testARuntimeErrorIsAJqExceptionCarryingTheErrorValue(): void
    {
        try {
            Jq::run('error("boom")', null);
            self::fail('expected a JqException');
        } catch (JqException $jqException) {
            self::assertSame('boom', $jqException->value);
        }
    }

    public function testARunAfterAnErrorIsUnaffected(): void
    {
        try {
            Jq::run('error("x")', null);
            self::fail('expected a JqException');
        } catch (JqException $jqException) {
            self::assertSame('x', $jqException->value);
        }

        self::assertSame([2], Jq::run('1 + 1', null));
    }
}
