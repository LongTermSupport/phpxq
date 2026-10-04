<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Cli\JqExitCode;
use LTS\PhpXq\Jq\Runtime\HaltException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class JqApplicationTest extends JqApplicationTestCase
{
    public function testCreateWiresTheProductionObjectGraph(): void
    {
        $application = JqApplication::create();

        self::assertSame($application->parser, $application->parser);
        self::assertNotSame($application, JqApplication::create());
    }

    public function testTheCycleCollectorIsOffDuringARunAndRestoredAfterwards(): void
    {
        $wasEnabled = gc_enabled();
        gc_enable();

        try {
            [$status] = $this->jq(['.'], '1');
            self::assertSame(0, $status);
            self::assertTrue(gc_enabled());

            gc_disable();
            $this->jq(['.'], '1');
            self::assertFalse(gc_enabled(), 'a collector the caller switched off stays off');
        } finally {
            if ($wasEnabled) {
                gc_enable();
            } else {
                gc_disable();
            }
        }
    }

    public function testManyInputsRunThroughTheManualCollectionInterval(): void
    {
        [$status, $out] = $this->jq(['-c', '.'], str_repeat("1\n", 9000));

        self::assertSame(0, $status);
        self::assertSame(9000, substr_count($out, "1\n"));
    }

    public function testIdentityPrettyPrintsEveryInput(): void
    {
        [$status, $out, $err] = $this->jq(['.'], "{\"a\":1}\n[1,2]");

        self::assertSame(0, $status);
        self::assertSame("{\n  \"a\": 1\n}\n[\n  1,\n  2\n]\n", $out);
        self::assertSame('', $err);
    }

    public function testNoProgramMeansIdentityWhenNotOnATerminal(): void
    {
        [$status, $out] = $this->jq([], '[1]');

        self::assertSame(0, $status);
        self::assertSame("[\n  1\n]\n", $out);
        self::assertSame(['.'], $this->parser->sources);
    }

    public function testCompactOutput(): void
    {
        [, $out] = $this->jq(['-c', '.'], '{"a":[1,2]}');

        self::assertSame("{\"a\":[1,2]}\n", $out);
    }

    public function testNullInputRunsOnceWithNullAndReadsNothing(): void
    {
        $seen           = [];
        [$status, $out] = $this->jq(['-n', '.'], '1 2 3', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$seen): void {
            $seen[] = $input;
            $emit(42);
        });

        self::assertSame(0, $status);
        self::assertSame("42\n", $out);
        self::assertSame([null], $seen);
    }

    public function testRawOutputWritesStringsVerbatim(): void
    {
        [, $out] = $this->jq(['-r', '.[]'], '["a\"b","c",1]', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            \assert(\is_array($input));
            foreach ($input as $item) {
                $emit($item);
            }
        });

        self::assertSame("a\"b\nc\n1\n", $out);
    }

    public function testJoinOutputWritesNoSeparator(): void
    {
        [, $out] = $this->jq(['-j', '.[]'], '["a","b",1]', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            \assert(\is_array($input));
            foreach ($input as $item) {
                $emit($item);
            }
        });

        self::assertSame('ab1', $out);
    }

    public function testRawOutput0TerminatesWithNul(): void
    {
        [$status, $out] = $this->jq(['--raw-output0', '.[]'], '["a","b"]', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            \assert(\is_array($input));
            foreach ($input as $item) {
                $emit($item);
            }
        });

        self::assertSame(0, $status);
        self::assertSame("a\0b\0", $out);
    }

    public function testRawOutput0RefusesAStringWithNul(): void
    {
        [$status, $out, $err] = $this->jq(['--raw-output0', '.[]'], '["a","c","b"]', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            $emit('a');
            $emit("c\0d");
            $emit('b');
        });

        self::assertSame(5, $status);
        self::assertSame("a\0", $out);
        self::assertSame("jq: error (at <stdin>:0): Cannot dump a string containing NUL with --raw-output0 option\n", $err);
    }

    public function testAsciiOutputWithRawStillQuotesStrings(): void
    {
        [, $out] = $this->jq(['-r', '-a', '.'], '"é"');

        self::assertSame("\"\\u00e9\"\n", $out);
    }

    public function testAsciiOutputEscapesNonAscii(): void
    {
        [, $out] = $this->jq(['-a', '-c', '.'], '["é","\ud83d\ude00"]');

        self::assertSame("[\"\\u00e9\",\"\\ud83d\\ude00\"]\n", $out);
    }

    public function testSlurpGivesTheProgramOneArray(): void
    {
        $seen = [];
        $this->jq(['-s', '.'], '1 2 [3]', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$seen): void {
            $seen[] = $input;
            $emit($input);
        });

        self::assertSame([[1, 2, [3]]], $seen);
    }

    public function testSlurpOfNothingIsAnEmptyArray(): void
    {
        [, $out] = $this->jq(['-s', '-c', '.'], '');

        self::assertSame("[]\n", $out);
    }

    public function testRawInputGivesEachLine(): void
    {
        $seen = [];
        $this->jq(['-R', '.'], "a\nb\n\nc", static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$seen): void {
            $seen[] = $input;
        });

        self::assertSame(['a', 'b', '', 'c'], $seen);
    }

    public function testRawSlurpGivesTheWholeText(): void
    {
        $seen = [];
        $this->jq(['-Rs', '.'], "a\0b\nc\n", static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$seen): void {
            $seen[] = $input;
        });

        self::assertSame(["a\0b\nc\n"], $seen);
    }

    public function testInputsBuiltinsShareTheMainStream(): void
    {
        $collected = [];
        $this->jq(['-n', '.'], '1 2 3', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$collected): void {
            $inputs = $context->inputs();
            while ($inputs->hasNext()) {
                $collected[] = $inputs->next();
            }

            try {
                $inputs->next();
            } catch (JqException $jqException) {
                $collected[] = $jqException->getMessage();
            }
        });

        self::assertSame([1, 2, 3, 'No more inputs'], $collected);
    }

    public function testInputInsideTheProgramConsumesFromTheMainLoop(): void
    {
        $calls = [];
        $this->jq(['.'], '1 2 3 4', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$calls): void {
            $calls[] = $input;
            $context->inputs()->next();
        });

        self::assertSame([1, 3], $calls);
    }

    public function testExitStatusFollowsTheLastOutput(): void
    {
        [$truthy] = $this->jq(['-e', '.'], '1');
        [$falsy]  = $this->jq(['-e', '.'], 'false');
        [$null]   = $this->jq(['-e', '.'], '1 null');
        [$none]   = $this->jq(['-e', '.'], '', static function (): void {
        });
        [$plain] = $this->jq(['.'], 'null');

        self::assertSame(0, $truthy);
        self::assertSame(1, $falsy);
        self::assertSame(1, $null);
        self::assertSame(4, $none);
        self::assertSame(0, $plain);
    }

    public function testExitStatusSkipsInputsWithoutOutput(): void
    {
        [$status] = $this->jq(['-e', '.'], '1 2', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            if (1 === $input) {
                $emit(false);
            }
        });

        self::assertSame(1, $status);
    }

    public function testRuntimeErrorIsReportedWithThePositionAndExits5(): void
    {
        [$status, $out, $err] = $this->jq(['.'], "1\n", static function (RuntimeContextInterface $context, mixed $input, Closure $emit): never {
            $emit('before');

            throw JqException::fromMessage('boom');
        });

        self::assertSame(5, $status);
        self::assertSame("\"before\"\n", $out);
        self::assertSame("jq: error (at <stdin>:1): boom\n", $err);
    }

    public function testRuntimeErrorWithoutATrailingNewlineIsAtLineZero(): void
    {
        [, , $err] = $this->jq(['.'], '1', static function (): never {
            throw JqException::fromMessage('boom');
        });

        self::assertSame("jq: error (at <stdin>:0): boom\n", $err);
    }

    public function testNonStringErrorValueIsPrintedAsJson(): void
    {
        [, , $err] = $this->jq(['.'], '1', static function (): never {
            throw new JqException(JsonObject::fromPairs(['a' => 1]));
        });

        self::assertSame("jq: error (at <stdin>:0) (not a string): {\"a\":1}\n", $err);
    }

    public function testErrorInNullInputModeIsAtUnknownPosition(): void
    {
        [$status, , $err] = $this->jq(['-n', '.'], '', static function (): never {
            throw JqException::fromMessage('boom');
        });

        self::assertSame(5, $status);
        self::assertSame("jq: error (at <unknown>): boom\n", $err);
    }

    public function testAnErrorOnOneInputDoesNotStopTheNext(): void
    {
        [$status, $out, $err] = $this->jq(['-c', '.'], "1\n2\n", static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            if (1 === $input) {
                throw JqException::fromMessage('first');
            }

            $emit($input);
        });

        self::assertSame("2\n", $out);
        self::assertSame("jq: error (at <stdin>:1): first\n", $err);
        self::assertSame(0, $status, 'the status is that of the last input, as in jq');
    }

    public function testHaltStopsTheRunWithItsExitCode(): void
    {
        $seen                 = [];
        [$status, $out, $err] = $this->jq(['.'], '1 2 3', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$seen): never {
            $seen[] = $input;
            $emit($input);

            throw new HaltException(7, "bye\n");
        });

        self::assertSame(7, $status);
        self::assertSame([1], $seen);
        self::assertSame("1\n", $out);
        self::assertSame("bye\n", $err);
    }

    public function testPlainHaltExitsZeroAndWritesNothing(): void
    {
        [$status, , $err] = $this->jq(['-n', '.'], '', static function (): never {
            throw new HaltException(0);
        });

        self::assertSame(0, $status);
        self::assertSame('', $err);
    }

    public function testCompileErrorExits3WithSnippetAndCount(): void
    {
        [$status, $out, $err] = $this->jq(['SYNTAX!'], '1');

        self::assertSame(3, $status);
        self::assertSame('', $out);
        self::assertSame(
            "jq: error: syntax error, unexpected INVALID_CHARACTER (Unix shell quoting issues?) at <top-level>, line 1, column 1:\n"
            . "    SYNTAX!\n    ^^^^^^\njq: 1 compile error\n",
            $err,
        );
    }

    public function testSeveralCompileErrorsAreCounted(): void
    {
        [$status, , $err] = $this->jq(["[\n  try if .\n         then 1\n         else 2\n  catch ]"], '1');

        self::assertSame(3, $status);
        self::assertStringStartsWith("jq: error: syntax error, unexpected catch, expecting end or '|' or ',' at <top-level>, line 5, column 3:\n      catch ]\n      ^^^^^\n", $err);
        self::assertStringContainsString("jq: error: Possibly unterminated 'try' statement at <top-level>, line 2, column 3:\n", $err);
        self::assertStringEndsWith("jq: 3 compile errors\n", $err);
    }

    public function testCompileErrorAtTheEndOfTheSource(): void
    {
        [, , $err] = $this->jq(['-n', "if\n"]);

        self::assertSame(
            "jq: error: syntax error, unexpected end of file at <top-level>, line 1, column 3:\n    if\n      ^\njq: 1 compile error\n",
            $err,
        );
    }

    public function testAProgramThatOnlyDefinesFunctionsIsRejected(): void
    {
        [$status, , $err] = $this->jq(['-n', 'DEFS']);

        self::assertSame(3, $status);
        self::assertSame("jq: error: Top-level program not given (try \".\")\njq: 1 compile error\n", $err);
    }

    public function testHomeDotJqDefinitionsComeFirst(): void
    {
        $home = sys_get_temp_dir() . '/jqcli-home-' . bin2hex(random_bytes(4));
        mkdir($home);
        file_put_contents($home . '/.jq', 'def home: 1;');
        $previous = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            [$status] = $this->jq(['.'], '1');
        } finally {
            putenv(false === $previous ? 'HOME' : 'HOME=' . $previous);
            unlink($home . '/.jq');
            rmdir($home);
        }

        self::assertSame(0, $status);
        self::assertNotNull($this->compiler->program);
        self::assertSame(['home'], array_map(static fn (\LTS\PhpXq\Jq\Ast\FuncDef $def): string => $def->name, $this->compiler->program->defs));
    }

    public function testLibraryPathsReachTheCompilerAndTheContext(): void
    {
        $paths = null;
        $this->jq(['-L', 'a', '-Lb', '--', '.'], '1', static function (RuntimeContextInterface $context) use (&$paths): void {
            $paths = $context->libraryPaths();
        });

        self::assertSame(['a', 'b'], $this->compiler->libraryPaths);
        self::assertSame(['a', 'b'], $paths);
    }

    public function testNamedAndPositionalArgumentsBecomeGlobals(): void
    {
        $globals = [];
        $this->jq(['-n', '--arg', 'a', '1', '--argjson', 'b', '[2]', '--args', '.', 'x', 'y'], '', static function (RuntimeContextInterface $context) use (&$globals): void {
            $globals = $context->globals();
        });

        self::assertSame(['ENV', '__prog_args', 'ARGS', 'a', 'b'], $this->compiler->globalNames);
        self::assertSame('1', $globals['a']);
        self::assertSame([2], $globals['b']);
        self::assertInstanceOf(JsonObject::class, $globals['ARGS']);
        self::assertSame(['x', 'y'], $globals['ARGS']->get('positional'));
        $named = $globals['ARGS']->get('named');
        self::assertInstanceOf(JsonObject::class, $named);
        self::assertSame(['a', 'b'], $named->keys());
        self::assertInstanceOf(JsonObject::class, $globals['ENV']);
        self::assertSame(getenv('PATH'), $globals['ENV']->get('PATH'));
    }

    public function testFilesAreReadInOrderAndInputFilenameFollows(): void
    {
        $first   = $this->tempFile("1\n");
        $second  = $this->tempFile('2');
        $names   = [];
        [, $out] = $this->jq(['-c', '.', $first, $second], '', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$names): void {
            $names[] = $context->inputFilename();
            $emit($input);
        });

        self::assertSame("1\n2\n", $out);
        self::assertSame([$first, $second], $names);
    }

    public function testStdinHasNoFilename(): void
    {
        $name = 'unset';
        $this->jq(['.'], '1', static function (RuntimeContextInterface $context) use (&$name): void {
            $name = $context->inputFilename();
        });

        self::assertNull($name);
    }

    public function testAnUnreadableFileIsReportedAndSkippedWithStatus2(): void
    {
        $good                 = $this->tempFile('7');
        [$status, $out, $err] = $this->jq(['-c', '.', '/nonexistent/phpxq.json', $good]);

        self::assertSame(2, $status);
        self::assertSame("7\n", $out);
        self::assertSame("jq: error: Could not open /nonexistent/phpxq.json: No such file or directory\n", $err);
    }

    public function testDashReadsStandardInput(): void
    {
        [, $out] = $this->jq(['-c', '.', '-'], '[9]');

        self::assertSame("[9]\n", $out);
    }

    public function testInvalidInputIsAParseErrorAfterTheValuesBeforeIt(): void
    {
        [$status, $out, $err] = $this->jq(['-c', '.'], '1 foobar 2');

        self::assertSame(5, $status);
        self::assertSame("1\n", $out);
        self::assertSame("jq: parse error: Invalid literal at line 1, column 9\n", $err);
    }

    public function testSeqWritesARecordSeparatorBeforeEachOutput(): void
    {
        [, $out] = $this->jq(['--seq', '-c', '.'], "\x1e[1]\n\x1e2\n");

        self::assertSame("\x1e[1]\n\x1e2\n", $out);
    }

    public function testSeqIgnoresDamagedRecordsWithAWarning(): void
    {
        [$status, $out, $err] = $this->jq(['--seq', '-c', '.'], "\x1e1\n\x1e[0,1\x1e2\n");

        self::assertSame(0, $status);
        self::assertSame("\x1e1\n\x1e2\n", $out);
        self::assertSame("jq: ignoring parse error: Truncated value at line 2, column 6\n", $err);
    }

    public function testStreamTurnsInputIntoEvents(): void
    {
        [, $out] = $this->jq(['--stream', '-c', '.'], '[1,[2]] "s"');

        self::assertSame("[[0],1]\n[[1,0],2]\n[[1,0]]\n[[1]]\n[[],\"s\"]\n", $out);
    }

    public function testStreamWithSlurpCollectsAllEvents(): void
    {
        [, $out] = $this->jq(['--stream', '-s', '-c', '.'], '[1][2]');

        self::assertSame("[[[0],1],[[0]],[[0],2],[[0]]]\n", $out);
    }

    public function testStreamErrorsReportsTheErrorAsAnEvent(): void
    {
        [$status, $out, $err] = $this->jq(['--stream-errors', '-c', '.'], '[');

        self::assertSame(0, $status);
        self::assertSame("[\"Unfinished JSON term at EOF at line 1, column 1\",[0]]\n", $out);
        self::assertSame('', $err);
    }

    public function testStreamErrorWithoutStreamErrorsIsAParseError(): void
    {
        [$status, $out, $err] = $this->jq(['--stream', '-c', '.'], '{"a":1,"b",');

        self::assertSame(5, $status);
        self::assertSame("[[\"a\"],1]\n", $out);
        self::assertSame("jq: parse error: Objects must consist of key:value pairs at line 1, column 11\n", $err);
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('provideLayouts')]
    public function testOutputLayout(array $args, string $expected): void
    {
        [$status, $out] = $this->jq($args, '[1,{"a":2}]');

        self::assertSame(0, $status);
        self::assertSame($expected, $out);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideLayouts(): iterable
    {
        yield 'default' => [['.'], "[\n  1,\n  {\n    \"a\": 2\n  }\n]\n"];

        yield 'indent 1' => [['--indent', '1', '.'], "[\n 1,\n {\n  \"a\": 2\n }\n]\n"];

        yield 'indent 7' => [['--indent', '7', '.'], "[\n       1,\n       {\n              \"a\": 2\n       }\n]\n"];

        yield 'tab' => [['--tab', '.'], "[\n\t1,\n\t{\n\t\t\"a\": 2\n\t}\n]\n"];

        yield 'indent minus one is tab' => [['--indent', '-1', '.'], "[\n\t1,\n\t{\n\t\t\"a\": 2\n\t}\n]\n"];

        yield 'indent 0 keeps the line breaks' => [['--indent', '0', '.'], "[\n1,\n{\n\"a\": 2\n}\n]\n"];

        yield 'compact' => [['-c', '.'], "[1,{\"a\":2}]\n"];

        yield 'compact then indent stays compact' => [['-c', '--indent', '3', '.'], "[1,{\"a\":2}]\n"];

        yield 'indent then compact' => [['--indent', '3', '-c', '.'], "[1,{\"a\":2}]\n"];

        yield 'compact then tab is a tab layout' => [['-c', '--tab', '.'], "[\n\t1,\n\t{\n\t\t\"a\": 2\n\t}\n]\n"];

        yield 'tab then compact is compact' => [['--tab', '-c', '.'], "[1,{\"a\":2}]\n"];
    }

    public function testSortKeys(): void
    {
        [, $out] = $this->jq(['-S', '-c', '.'], '{"b":1,"a":{"d":1,"c":2}}');

        self::assertSame("{\"a\":{\"c\":2,\"d\":1},\"b\":1}\n", $out);
    }

    public function testIndentOutOfRangeIsRefused(): void
    {
        [$status, , $err] = $this->jq(['--indent', '8', '.']);

        self::assertSame(2, $status);
        self::assertStringStartsWith("jq: Cannot indent more than 7 characters\nUse jq --help", $err);
    }

    public function testColorFlagUsesTheDefaultPalette(): void
    {
        $out = '';
        $this->withEnv(['JQ_COLORS' => false, 'NO_COLOR' => false], function () use (&$out): void {
            [, $out] = $this->jq(['-C', '-c', '.'], '[{"a":true,"b":false},"abc",123,null]');
        });

        self::assertSame(
            "\e[1;39m[\e[0m\e[1;39m{\e[0m\e[1;34m\"a\"\e[0m\e[1;39m:\e[0m\e[0;39mtrue\e[0m\e[1;39m,\e[0m"
            . "\e[1;34m\"b\"\e[0m\e[1;39m:\e[0m\e[0;39mfalse\e[0m\e[1;39m}\e[0m\e[1;39m,\e[0m"
            . "\e[0;32m\"abc\"\e[0m\e[1;39m,\e[0m\e[0;39m123\e[0m\e[1;39m,\e[0m\e[0;90mnull\e[0m\e[1;39m]\e[0m\n",
            $out,
        );
    }

    public function testJqColorsOverridesThePalette(): void
    {
        $out = '';
        $this->withEnv(['JQ_COLORS' => '4;31'], function () use (&$out): void {
            [, $out] = $this->jq(['-C', '-c', '.'], 'null');
        });

        self::assertSame("\e[4;31mnull\e[0m\n", $out);
    }

    public function testInvalidJqColorsWarnsAndKeepsTheDefaults(): void
    {
        $out = '';
        $err = '';
        $this->withEnv(['JQ_COLORS' => '30m'], function () use (&$out, &$err): void {
            [, $out, $err] = $this->jq(['-C', '-c', '.'], 'null');
        });

        self::assertSame("Failed to set \$JQ_COLORS\n", $err);
        self::assertSame("\e[0;90mnull\e[0m\n", $out);
    }

    public function testMonochromeWinsOverColorEnvironment(): void
    {
        $out = '';
        $this->withEnv(['JQ_COLORS' => '4;31'], function () use (&$out): void {
            [, $out] = $this->jq(['-C', '-M', '.'], 'null');
        });

        self::assertSame("null\n", $out);
    }

    public function testNoColorsWhenNotOnATerminal(): void
    {
        [, $out] = $this->jq(['.'], 'null');

        self::assertSame("null\n", $out);
    }

    public function testDebugAndStderrWriteToStandardError(): void
    {
        [, $out, $err] = $this->jq(['-n', '.'], '', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            $context->debug('x');
            $context->writeStderr('raw');
            $context->writeStderr([1]);
            $emit(1);
        });

        self::assertSame("1\n", $out);
        self::assertSame("[\"DEBUG:\",\"x\"]\nraw[1]", $err);
    }

    public function testProgramFromFile(): void
    {
        $file     = $this->tempFile('SOURCE-FROM-FILE');
        [$status] = $this->jq(['-n', '-f', $file]);

        self::assertSame(0, $status);
        self::assertSame(['SOURCE-FROM-FILE'], $this->parser->sources);
    }

    public function testFilterFileIsTheFirstPositionalAndTheRestAreInputs(): void
    {
        $program = $this->tempFile('.');
        $input   = $this->tempFile('5');
        [, $out] = $this->jq(['-c', '-f', $program, $input]);

        self::assertSame("5\n", $out);
    }

    public function testMissingProgramFileExits2(): void
    {
        [$status, , $err] = $this->jq(['-f', '/nonexistent/prog.jq']);

        self::assertSame(2, $status);
        self::assertSame("jq: error: Could not open /nonexistent/prog.jq: No such file or directory\n", $err);
    }

    public function testFromFileWithoutAFileIsAUsageError(): void
    {
        [$status, , $err] = $this->jq(['-n', '--from-file']);

        self::assertSame(2, $status);
        self::assertStringStartsWith('Usage:', $err);
    }

    public function testProgramFileWithNulByteIsACompileError(): void
    {
        $file             = $this->tempFile(".\0invalid");
        [$status, , $err] = $this->jq(['-n', '-f', $file]);

        self::assertSame(3, $status);
        self::assertStringContainsString('NUL', $err);
    }

    public function testUnknownOptionIsRefused(): void
    {
        [$status, $out, $err] = $this->jq(['--bogus', '.']);

        self::assertSame(2, $status);
        self::assertSame('', $out);
        self::assertSame("jq: Unknown option: --bogus\nUse jq --help for help with command-line options,\nor see the jq manpage, or online docs  at https://jqlang.org\n", $err);
    }

    public function testHelpGoesToStandardOutput(): void
    {
        [$status, $out, $err] = $this->jq(['-h']);

        self::assertSame(0, $status);
        self::assertStringStartsWith("Usage:\tjq [OPTIONS] FILTER [FILES...]\n", $out);
        self::assertStringContainsString("  --                        terminates argument processing;\n", $out);
        self::assertSame('', $err);
    }

    public function testVersionNamesTheTargetedJq(): void
    {
        [$status, $out] = $this->jq(['--version']);

        self::assertSame(0, $status);
        self::assertSame("jq-1.8.2\n", $out);
    }

    public function testFirstOfHelpAndVersionWins(): void
    {
        self::assertSame($this->jq(['-h']), $this->jq(['-hV']));
        self::assertSame($this->jq(['-h']), $this->jq(['-h', '-V']));
        self::assertSame($this->jq(['-V']), $this->jq(['-Vh']));
        self::assertSame($this->jq(['-V']), $this->jq(['-V', '-h']));
    }

    public function testBuildConfigurationIsPrinted(): void
    {
        [$status, $out] = $this->jq(['--build-configuration']);

        self::assertSame(0, $status);
        self::assertStringStartsWith('phpxq', $out);
    }

    public function testRunReturnsExitCodesFromJqExitCode(): void
    {
        [$status] = $this->jq(['-e', '.'], '');

        self::assertSame(JqExitCode::NO_OUTPUT, $status);
    }

    public function testDebugDumpDisasmPrintsTheBoundDefinitionsFirst(): void
    {
        [$status, $out] = $this->jq(['-n', '--debug-dump-disasm', '.'], '', static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            $emit(1);
        });

        self::assertSame(0, $status);
        self::assertSame("TOP\n1\n", $out);
    }

    public function testExitStatusAfterHaltUsesTheHaltCode(): void
    {
        [$status] = $this->jq(['-e', '-n', '.'], '', static function (): never {
            throw new HaltException(3);
        });

        self::assertSame(3, $status);
    }

    public function testStreamErrorsWithSeqKeepsGoing(): void
    {
        [$status, $out, $err] = $this->jq(['--seq', '--stream-errors', '-c', '.'], "\x1e[1\x1e[2]");

        self::assertSame(0, $status);
        self::assertSame('', $err);
        self::assertStringContainsString("\x1e[[0],2]\n", $out);
        self::assertStringContainsString('Truncated value', $out);
    }

    public function testSlurpWithFilesUsesTheLastFilesName(): void
    {
        $first   = $this->tempFile('1');
        $second  = $this->tempFile('2');
        $name    = null;
        [, $out] = $this->jq(['-s', '-c', '.', $first, $second], '', static function (RuntimeContextInterface $context, mixed $input, Closure $emit) use (&$name): void {
            $name = $context->inputFilename();
            $emit($input);
        });

        self::assertSame("[1,2]\n", $out);
        self::assertSame($second, $name);
    }

    public function testRawInputKeepsGoingAcrossFiles(): void
    {
        $first   = $this->tempFile("a\nb\n");
        $second  = $this->tempFile('c');
        [, $out] = $this->jq(['-R', '-r', '.', $first, $second]);

        self::assertSame("a\nb\nc\n", $out);
    }

    public function testNullInputDoesNotOpenInputFiles(): void
    {
        [$status, , $err] = $this->jq(['-n', '.', '/nonexistent/never-opened.json']);

        self::assertSame(0, $status);
        self::assertSame('', $err);
    }

    public function testNullInputWithAnUnreadableFileReadViaInputsExits2(): void
    {
        [$status, , $err] = $this->jq(['-n', '.', '/nonexistent/x.json'], '', static function (RuntimeContextInterface $context): void {
            $context->inputs()->hasNext();
        });

        self::assertSame(2, $status);
        self::assertStringContainsString('Could not open /nonexistent/x.json', $err);
    }

    public function testOutputThatCannotBeWrittenAtTheEndFailsTheRun(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('/dev/full is not available');
        }

        $full = fopen('/dev/full', 'wb');
        self::assertIsResource($full);
        $err = self::memory('');

        $status = new JqApplication(
            new JqApplicationFakeParser(),
            new JqApplicationFakeCompiler(static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
                $emit('x');
            }),
            new JsonDecoder(),
            new JsonEncoder(),
        )->run(self::memory(''), $full, $err, '-n', '.');

        self::assertSame(JqExitCode::USAGE, $status);
        self::assertSame("jq: error: writing output failed: No space left on device\n", self::contents($err));
        fclose($full);
    }

    public function testAReaderThatWentAwayEndsTheRunQuietlyLikeSigpipe(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$reader, $writer] = $pair;
        fclose($reader);
        $err = self::memory('');

        $status = new JqApplication(
            new JqApplicationFakeParser(),
            new JqApplicationFakeCompiler(static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
                $emit('x');
            }),
            new JsonDecoder(),
            new JsonEncoder(),
        )->run(self::memory(''), $writer, $err, '-n', '.');

        self::assertSame(JqExitCode::BROKEN_PIPE, $status);
        self::assertSame('', self::contents($err));
    }

    /**
     * @param array<string, string|false> $variables false unsets
     */
    private function withEnv(array $variables, Closure $action): void
    {
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv(false === $value ? $name : $name . '=' . $value);
        }

        try {
            $action();
        } finally {
            foreach ($previous as $name => $value) {
                putenv(false === $value ? $name : $name . '=' . $value);
            }
        }
    }
}
