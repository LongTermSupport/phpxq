<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Jq\Cli\Options\OptionParser;
use LTS\PhpXq\Json\JsonDecoder;
use PHPUnit\Framework\TestCase;

/**
 * Options whose parameters are the very last arguments, and the defaults of a bare command line.
 *
 * @internal
 */
final class OptionParserBoundaryTest extends TestCase
{
    private const string VALUE = 'text';

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

    public function testABareProgramLeavesEveryFlagOff(): void
    {
        $options = $this->parse('.');

        self::assertSame('.', $options->program);
        self::assertSame([], $options->files);
        self::assertSame([], $options->libraryPaths);
        self::assertSame([], $options->named);
        self::assertSame([], $options->positional);
        self::assertNull($options->color);
        self::assertTrue($options->pretty);
        self::assertFalse($options->tab);
        self::assertSame(2, $options->indent);
        foreach (['nullInput', 'rawInput', 'slurp', 'rawOutput', 'rawOutput0', 'joinOutput', 'ascii', 'sortKeys', 'exitStatus', 'seq', 'stream', 'streamErrors', 'unbuffered', 'fromFile', 'debugDumpDisasm'] as $flag) {
            self::assertFalse($options->{$flag}, $flag);
        }
    }

    public function testALibraryPathCanBeTheLastArgument(): void
    {
        $options = $this->parse('-L', '/lib/path');

        self::assertSame(['/lib/path'], $options->libraryPaths);
        self::assertNull($options->program);
    }

    public function testIndentCanBeTheLastArgument(): void
    {
        self::assertSame(3, $this->parse('--indent', '3')->indent);
        self::assertSame(7, $this->parse('--indent', '7')->indent);
    }

    public function testNamedArgumentsCanBeTheLastArguments(): void
    {
        self::assertSame(['v' => self::VALUE], $this->parse('--arg', 'v', self::VALUE)->named);
        self::assertSame(['v' => [1, 2]], $this->parse('--argjson', 'v', '[1,2]')->named);
    }

    public function testNamedArgumentsAreFollowedByTheProgram(): void
    {
        $options = $this->parse('--arg', 'v', self::VALUE, '--argjson', 'j', '1', '.x', 'file.json');

        self::assertSame(['v' => self::VALUE, 'j' => 1], $options->named);
        self::assertSame('.x', $options->program);
        self::assertSame(['file.json'], $options->files);
    }

    public function testFileArgumentsCanBeTheLastArguments(): void
    {
        $path = $this->tempFile("1 2 3\n");

        self::assertSame(['v' => [1, 2, 3]], $this->parse('--slurpfile', 'v', $path)->named);
        self::assertSame(['v' => "1 2 3\n"], $this->parse('--rawfile', 'v', $path)->named);
    }

    public function testFileArgumentsAreFollowedByTheProgram(): void
    {
        $path    = $this->tempFile('[4]');
        $options = $this->parse('--slurpfile', 'v', $path, '--rawfile', 'w', $path, '.');

        self::assertSame(['v' => [[4]], 'w' => '[4]'], $options->named);
        self::assertSame('.', $options->program);
    }

    private function parse(string ...$args): CliOptions
    {
        return new OptionParser(new JsonDecoder())->parse(...$args);
    }

    /**
     * @return non-empty-string
     */
    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jqopt');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
