<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\InputItem;
use LTS\PhpXq\Jq\Cli\InputSource;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Json\JsonDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Edges of where input comes from and which line it is reported on.
 *
 * @internal
 */
final class JqApplicationInputSourceEdgesTest extends TestCase
{
    private const string BOM = "\xEF\xBB\xBF";

    private const string SEPARATOR = "\x1e";

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

    public function testTheLineBeforeAnyInputIsZero(): void
    {
        $source = $this->source("1\n2\n");

        self::assertSame(0, $source->lineNumber());
        self::assertNull($source->filename());
    }

    public function testSlurpingNothingReportsLineZero(): void
    {
        $item = $this->source('', [], new CliOptions(slurp: true))->fetch();

        self::assertInstanceOf(InputItem::class, $item);
        self::assertSame([], $item->value);
        self::assertSame(0, $item->lineNumber());
    }

    public function testSlurpReportsTheLineOfTheLastValue(): void
    {
        $item = $this->source("1\n2\n3", [], new CliOptions(slurp: true))->fetch();

        self::assertInstanceOf(InputItem::class, $item);
        self::assertSame([1, 2, 3], $item->value);
        self::assertSame(2, $item->lineNumber());
    }

    public function testRawSlurpCountsEveryNewline(): void
    {
        $item = $this->source("a\nb\n", [], new CliOptions(rawInput: true, slurp: true))->fetch();

        self::assertInstanceOf(InputItem::class, $item);
        self::assertSame("a\nb\n", $item->value);
        self::assertSame(2, $item->lineNumber());
    }

    public function testStreamEventsSkipAByteOrderMark(): void
    {
        $source = $this->source(self::BOM . '[1]', [], new CliOptions(stream: true));

        self::assertSame([[[0], 1], [[0]]], $this->values($source));
    }

    public function testASeqErrorInOneFileDoesNotEndTheNextFile(): void
    {
        $damaged = $this->tempFile(self::SEPARATOR . "[\n");
        $good    = $this->tempFile(self::SEPARATOR . "2\n");
        $source  = $this->source('', [$damaged, $good], new CliOptions(seq: true));

        $items = $this->items($source);

        self::assertTrue($items[0]->isError());
        self::assertFalse($items[0]->fatal);
        self::assertSame(2, $items[\count($items) - 1]->value);
    }

    public function testDashInTheMiddleOfTheFilesReadsStandardInputThenContinues(): void
    {
        $first  = $this->tempFile('1');
        $second = $this->tempFile('3');

        self::assertSame([1, 2, 3], $this->values($this->source('2', [$first, '-', $second])));
    }

    public function testRawInputWithoutATrailingNewlineKeepsTheLastLine(): void
    {
        self::assertSame(['a', 'b'], $this->values($this->source("a\nb", [], new CliOptions(rawInput: true))));
        self::assertSame(['a', 'b', 'c'], $this->values($this->source("a\nb\nc", [], new CliOptions(rawInput: true))));
    }

    public function testSeqValuesAreOnLineZero(): void
    {
        $items = $this->items($this->source(self::SEPARATOR . "1\n" . self::SEPARATOR . "2\n", [], new CliOptions(seq: true)));

        self::assertCount(2, $items);
        self::assertSame(0, $items[0]->lineNumber());
        self::assertSame(0, $items[1]->lineNumber());
    }

    public function testAParseErrorOnTheFirstLineIsOnLineZero(): void
    {
        $items = $this->items($this->source('{"a"'));

        self::assertTrue($items[0]->isError());
        self::assertSame(0, $items[0]->lineNumber());
    }

    public function testAParseErrorIsOnTheLineBeforeItsReportedLine(): void
    {
        $items = $this->items($this->source("1\n2\n{\"a\"\n"));
        $last  = $items[\count($items) - 1];

        self::assertTrue($last->isError());
        self::assertMatchesRegularExpression('/at line (\d+), column \d+/', (string)$last->error);
        self::assertSame(3, $last->lineNumber());
    }

    public function testAParseErrorWithoutAPositionCountsEveryNewline(): void
    {
        $items = $this->items($this->source("[1,\n2\n"));
        $last  = $items[\count($items) - 1];

        self::assertTrue($last->isError());
        self::assertSame(2, $last->lineNumber());
    }

    public function testStreamEventsFollowTheirLinesAcrossBlankLines(): void
    {
        $items = $this->items($this->source("[1,\n\n2]\n3\n", [], new CliOptions(stream: true)));
        $pairs = [];
        foreach ($items as $item) {
            $pairs[] = [$item->value, $item->lineNumber()];
        }

        self::assertSame([[[[0], 1], 1], [[[1], 2], 3], [[[1]], 3], [[[], 3], 4]], $pairs);
    }

    public function testStreamErrorsAreNotRaisedTwiceAcrossFiles(): void
    {
        $first  = $this->tempFile('[1,');
        $second = $this->tempFile('[2]');
        $items  = $this->items($this->source('', [$first, $second], new CliOptions(stream: true)));

        self::assertTrue($items[\count($items) - 1]->isError());
    }

    /**
     * @param list<string> $files
     */
    private function source(string $stdin, array $files = [], ?CliOptions $options = null): InputSource
    {
        $stream = fopen('php://memory', 'w+b');
        if (false === $stream) {
            throw new RuntimeException('no stream');
        }

        fwrite($stream, $stdin);
        rewind($stream);

        return new InputSource($files, $stream, new JsonDecoder(), $options ?? new CliOptions(), static function (string $message): void {
        });
    }

    /**
     * @return list<InputItem>
     */
    private function items(InputSource $source): array
    {
        $items = [];
        while (($item = $source->fetch()) instanceof InputItem) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return list<mixed>
     */
    private function values(InputSource $source): array
    {
        $values = [];
        foreach ($this->items($source) as $item) {
            self::assertFalse($item->isError(), (string)$item->error);
            $values[] = $item->value;
        }

        return $values;
    }

    /**
     * @return non-empty-string
     */
    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jqin');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
