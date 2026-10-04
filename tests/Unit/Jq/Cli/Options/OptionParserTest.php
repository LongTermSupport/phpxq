<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliActionEnum;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Jq\Cli\Options\OptionParser;
use LTS\PhpXq\Jq\Cli\Options\UsageException;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class OptionParserTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testProgramAndFiles(): void
    {
        $options = $this->parse(['.a', 'one.json', 'two.json']);

        self::assertSame(CliActionEnum::Run, $options->action);
        self::assertSame('.a', $options->program);
        self::assertSame(['one.json', 'two.json'], $options->files);
        self::assertSame([], $options->positional);
    }

    public function testNoProgramLeavesItNull(): void
    {
        self::assertNull($this->parse([])->program);
        self::assertNull($this->parse(['-n'])->program);
    }

    public function testShortFlagsCombine(): void
    {
        $options = $this->parse(['-nrsSae', '.']);

        self::assertTrue($options->nullInput);
        self::assertTrue($options->rawOutput);
        self::assertTrue($options->slurp);
        self::assertTrue($options->sortKeys);
        self::assertTrue($options->ascii);
        self::assertTrue($options->exitStatus);
        self::assertFalse($options->rawInput);
        self::assertSame('.', $options->program);
    }

    public function testJoinOutputImpliesRaw(): void
    {
        $options = $this->parse(['-j', '.']);

        self::assertTrue($options->joinOutput);
        self::assertTrue($options->rawOutput);
    }

    public function testRawOutput0ImpliesRawAndNoNewline(): void
    {
        $options = $this->parse(['--raw-output0', '.']);

        self::assertTrue($options->rawOutput0);
        self::assertTrue($options->rawOutput);
        self::assertTrue($options->joinOutput);
    }

    public function testLongFlags(): void
    {
        $options = $this->parse([
            '--null-input', '--raw-input', '--slurp', '--raw-output', '--ascii-output', '--sort-keys',
            '--exit-status', '--seq', '--unbuffered', '--binary', '--from-file', '--debug-dump-disasm', '--debug-trace=all', '.',
        ]);

        self::assertTrue($options->nullInput);
        self::assertTrue($options->rawInput);
        self::assertTrue($options->slurp);
        self::assertTrue($options->rawOutput);
        self::assertTrue($options->ascii);
        self::assertTrue($options->sortKeys);
        self::assertTrue($options->exitStatus);
        self::assertTrue($options->seq);
        self::assertTrue($options->unbuffered);
        self::assertTrue($options->fromFile);
        self::assertTrue($options->debugDumpDisasm);
    }

    public function testStreamErrorsImpliesStream(): void
    {
        $options = $this->parse(['--stream-errors', '.']);

        self::assertTrue($options->stream);
        self::assertTrue($options->streamErrors);
        self::assertFalse($this->parse(['--stream', '.'])->streamErrors);
    }

    public function testColorFlagsLastOneWins(): void
    {
        self::assertTrue($this->parse(['-C', '.'])->color);
        self::assertFalse($this->parse(['-M', '.'])->color);
        self::assertFalse($this->parse(['-C', '-M', '.'])->color);
        self::assertTrue($this->parse(['--monochrome-output', '--color-output', '.'])->color);
        self::assertNull($this->parse(['.'])->color);
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('provideLayouts')]
    public function testLayoutFlagsActInOrder(array $args, bool $pretty, bool $tab, int $indent): void
    {
        $options = $this->parse($args);

        self::assertSame($pretty, $options->pretty);
        self::assertSame($tab, $options->tab);
        self::assertSame($indent, $options->indent);
    }

    /**
     * @return iterable<string, array{list<string>, bool, bool, int}>
     */
    public static function provideLayouts(): iterable
    {
        yield 'default' => [['.'], true, false, 2];

        yield 'compact' => [['-c', '.'], false, false, 0];

        yield 'tab' => [['--tab', '.'], true, true, 0];

        yield 'indent 5' => [['--indent', '5', '.'], true, false, 5];

        yield 'indent -1' => [['--indent', '-1', '.'], true, true, 0];

        yield 'indent after compact' => [['-c', '--indent', '3', '.'], false, false, 3];

        yield 'tab after compact' => [['-c', '--tab', '.'], true, true, 0];

        yield 'compact after tab' => [['--tab', '-c', '.'], false, false, 0];

        yield 'indent after tab' => [['--tab', '--indent', '4', '.'], true, false, 4];

        yield 'compact-output long' => [['--compact-output', '.'], false, false, 0];
    }

    public function testIndentLimits(): void
    {
        self::assertSame(7, $this->parse(['--indent', '7', '.'])->indent);
        $this->assertRefused(['--indent', '8', '.'], 'jq: Cannot indent more than 7 characters');
        $this->assertRefused(['--indent', '-2', '.'], 'jq: Cannot indent less than -1 characters');
        $this->assertRefused(['--indent'], 'jq: --indent takes one parameter');
    }

    public function testArgsAndJsonargsSwitchTheMeaningOfPositionals(): void
    {
        $options = $this->parse(['-n', '$ARGS.positional', '--args', 'foo', 'bar', '--jsonargs', '1', '{"a":[]}', '--args', 'baz']);

        self::assertSame('$ARGS.positional', $options->program);
        self::assertSame([], $options->files);
        self::assertCount(5, $options->positional);
        self::assertSame('foo', $options->positional[0]);
        self::assertSame('bar', $options->positional[1]);
        self::assertSame(1, $options->positional[2]);
        self::assertInstanceOf(JsonObject::class, $options->positional[3]);
        self::assertSame('baz', $options->positional[4]);
    }

    public function testArgsBeforeTheProgramLeavesTheFirstPositionalAsProgram(): void
    {
        $options = $this->parse(['--args', '-rn', '--', '$ARGS.positional[0]', 'bar']);

        self::assertSame('$ARGS.positional[0]', $options->program);
        self::assertSame(['bar'], $options->positional);
    }

    public function testProgramGivenBeforeDoubleDash(): void
    {
        $options = $this->parse(['--args', '-rn', '1', '--', '$ARGS.positional[0]', 'bar']);

        self::assertSame('1', $options->program);
        self::assertSame(['$ARGS.positional[0]', 'bar'], $options->positional);
    }

    public function testDoubleDashEndsOptions(): void
    {
        $options = $this->parse(['-n', '--', '-x', '--arg']);

        self::assertSame('-x', $options->program);
        self::assertSame(['--arg'], $options->files);
    }

    public function testASingleDashIsAFileName(): void
    {
        $options = $this->parse(['.', '-']);

        self::assertSame(['-'], $options->files);
    }

    public function testInvalidJsonargIsRefused(): void
    {
        $this->assertRefused(['-n', '--jsonargs', 'null', 'invalid'], 'jq: Invalid JSON text passed to --jsonargs');
        $this->assertRefused(['-n', '--jsonargs', 'null', '--', 'invalid'], 'jq: Invalid JSON text passed to --jsonargs');
    }

    public function testNamedArguments(): void
    {
        $options = $this->parse(['-n', '--arg', 'a', 'x', '--argjson', 'b', '{"k":1}', '.']);

        self::assertSame(['a', 'b'], array_keys($options->named));
        self::assertSame('x', $options->named['a']);
        self::assertInstanceOf(JsonObject::class, $options->named['b']);
        self::assertSame(['ENV', '__prog_args', 'ARGS', 'a', 'b'], $options->globalNames());
    }

    public function testTheFirstDefinitionOfANamedArgumentWins(): void
    {
        $file    = $this->tempFile("[2]\n");
        $options = $this->parse(['-n', '--arg', 'a', '1', '--arg', 'a', '2', '--argjson', 'a', '3', '--slurpfile', 'a', $file, '.']);

        self::assertSame(['a' => '1'], $options->named);

        $options = $this->parse(['-n', '--argjson', 'b', '3', '--arg', 'b', '2', '.']);

        self::assertSame(['b' => 3], $options->named);
    }

    public function testADuplicateNamedArgumentIsStillValidated(): void
    {
        $this->assertRefused(['--arg', 'a', '1', '--argjson', 'a', '1x'], 'jq: Invalid JSON text passed to --argjson');
    }

    public function testNamedArgumentErrors(): void
    {
        $this->assertRefused(['--arg', 'a'], 'jq: --arg takes two parameters (e.g. --arg varname value)');
        $this->assertRefused(['--argjson', 'a', '1x'], 'jq: Invalid JSON text passed to --argjson');
        $this->assertRefused(['--argjson', 'a'], 'jq: --argjson takes two parameters (e.g. --argjson varname text)');
        $this->assertRefused(['--slurpfile', 'a'], 'jq: --slurpfile takes two parameters (e.g. --slurpfile varname filename)');
        $this->assertRefused(['--rawfile', 'a'], 'jq: --rawfile takes two parameters (e.g. --rawfile varname filename)');
    }

    public function testSlurpfileAndRawfile(): void
    {
        $file    = $this->tempFile("{\"this\":1}\n[2]\n");
        $options = $this->parse(['-n', '--slurpfile', 'foo', $file, '--rawfile', 'bar', $file, '.']);

        self::assertIsArray($options->named['foo']);
        self::assertCount(2, $options->named['foo']);
        self::assertSame("{\"this\":1}\n[2]\n", $options->named['bar']);
    }

    public function testUnreadableFileForSlurpfile(): void
    {
        $this->assertRefused(['--slurpfile', 'a', '/nonexistent/x.json', '.'], 'jq: Bad JSON in --slurpfile a /nonexistent/x.json: Could not open /nonexistent/x.json: No such file or directory');
        $this->assertRefused(['--rawfile', 'a', '/nonexistent/x.txt', '.'], 'jq: Bad JSON in --rawfile a /nonexistent/x.txt: Could not open /nonexistent/x.txt: No such file or directory');
    }

    public function testBadJsonInSlurpfile(): void
    {
        $file = $this->tempFile('{"a":1} nope');

        try {
            $this->parse(['--slurpfile', 'a', $file, '.']);
        } catch (UsageException $usageException) {
            self::assertStringStartsWith('jq: Bad JSON in --slurpfile a ' . $file . ': ', $usageException->getMessage());

            return;
        }

        self::fail('Expected a UsageException');
    }

    public function testLibraryPathForms(): void
    {
        self::assertSame(['a', 'b', 'c', 'd'], $this->parse(['-L', 'a', '-Lb', '-nLc', '-n', '-L', 'd', '.'])->libraryPaths);
        self::assertSame(['.'], $this->parse(['-nL.', '42'])->libraryPaths);
        self::assertSame(['.'], $this->parse(['-nL', '.', '42'])->libraryPaths);
        $this->assertRefused(['-L'], 'jq: -L takes a parameter: (e.g. -L /search/path or -L/search/path)');
    }

    public function testHelpAndVersionEndParsingImmediately(): void
    {
        self::assertSame(CliActionEnum::Help, $this->parse(['-h'])->action);
        self::assertSame(CliActionEnum::Help, $this->parse(['--help'])->action);
        self::assertSame(CliActionEnum::Version, $this->parse(['-V'])->action);
        self::assertSame(CliActionEnum::Version, $this->parse(['--version'])->action);
        self::assertSame(CliActionEnum::BuildConfiguration, $this->parse(['--build-configuration'])->action);
        self::assertSame(CliActionEnum::Help, $this->parse(['-hV'])->action);
        self::assertSame(CliActionEnum::Version, $this->parse(['-Vh'])->action);
        self::assertSame(CliActionEnum::Help, $this->parse(['-h', '-V'])->action);
        self::assertSame(CliActionEnum::Version, $this->parse(['-V', '-h'])->action);
        self::assertSame(CliActionEnum::Help, $this->parse(['-n', '-h', '--bogus'])->action);
    }

    public function testUnknownOptionsAreRefused(): void
    {
        $this->assertRefused(['--bogus'], 'jq: Unknown option: --bogus');
        $this->assertRefused(['-nz', '.'], 'jq: Unknown option: -nz');
        $this->assertRefused(['--null', '.'], 'jq: Unknown option: --null');
    }

    public function testRefusalIsReachedAtItsPosition(): void
    {
        $this->expectExceptionObject(new UsageException('jq: Unknown option: --bogus'));
        $this->parse(['-n', '--bogus', '-h']);
    }

    public function testEmptyArgumentIsTheProgram(): void
    {
        self::assertSame('', $this->parse([''])->program);
    }

    /**
     * @param list<string> $args
     */
    private function parse(array $args): CliOptions
    {
        return new OptionParser(new JsonDecoder())->parse($args);
    }

    /**
     * @param list<string> $args
     */
    private function assertRefused(array $args, string $message): void
    {
        try {
            $this->parse($args);
        } catch (UsageException $usageException) {
            self::assertSame($message, $usageException->getMessage());
            self::assertFalse($usageException->showShortUsage);

            return;
        }

        self::fail('Expected a UsageException for ' . implode(' ', $args));
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jqopt');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
