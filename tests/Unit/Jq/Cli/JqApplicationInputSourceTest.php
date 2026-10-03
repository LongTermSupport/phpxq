<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\InputItem;
use LTS\PhpXq\Jq\Cli\InputSource;
use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use LTS\PhpXq\Jq\Runtime\JqException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class JqApplicationInputSourceTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testValuesCarryTheirLineNumber(): void
    {
        $source = $this->source("1\n2\n[3,\n4]\n5");

        self::assertSame([[1, 1], [2, 2], [[3, 4], 4], [5, 4]], $this->drain($source));
    }

    public function testSeveralValuesOnOneLineShareTheLine(): void
    {
        $source = $this->source("1 2 3\n4");

        self::assertSame([[1, 1], [2, 1], [3, 1], [4, 1]], $this->drain($source));
    }

    public function testNoNewlineMeansLineZero(): void
    {
        self::assertSame([[1, 0]], $this->drain($this->source('1')));
    }

    public function testNumberTerminatedByNewlineIsOnThatLine(): void
    {
        self::assertSame([[12, 1]], $this->drain($this->source("12\n")));
    }

    public function testLongLineOfValuesIsScannedOnce(): void
    {
        $text   = str_repeat('1 ', 20000) . "\n";
        $values = $this->drain($this->source($text));

        self::assertCount(20000, $values);
        self::assertSame([1, 1], $values[19999]);
    }

    public function testPositionLabelBeforeAndAfterTheFirstInput(): void
    {
        $source = $this->source("7\n");
        self::assertSame('<unknown>', $source->positionLabel());
        self::assertNull($source->filename());

        $source->fetch();

        self::assertSame('<stdin>:1', $source->positionLabel());
        self::assertSame(1, $source->lineNumber());
    }

    public function testHasNextDoesNotMoveThePosition(): void
    {
        $source = $this->source("1\n2\n");
        $source->fetch();

        self::assertTrue($source->hasNext());
        self::assertSame(1, $source->lineNumber());
        self::assertSame(2, $source->next());
        self::assertSame(2, $source->lineNumber());
        self::assertFalse($source->hasNext());
    }

    public function testNextAtTheEndRaisesNoMoreInputs(): void
    {
        $source = $this->source('');

        $this->expectExceptionObject(JqException::fromMessage('No more inputs'));
        $source->next();
    }

    public function testFetchAtTheEndReturnsNullRepeatedly(): void
    {
        $source = $this->source('1');
        $source->fetch();

        self::assertNull($source->fetch());
        self::assertNull($source->fetch());
        self::assertFalse($source->hasNext());
    }

    public function testByteOrderMarkIsSkipped(): void
    {
        self::assertSame([[1, 0]], $this->drain($this->source("\xEF\xBB\xBF1")));
    }

    public function testFilesAreReadInOrderWithTheirNames(): void
    {
        $first  = $this->tempFile("1\n");
        $second = $this->tempFile("2\n3");
        $source = $this->source('', [$first, $second]);

        $items = [];
        while (($item = $source->fetch()) instanceof InputItem) {
            $items[] = [$item->value, $item->filename === $first ? 'first' : 'second', $item->line, $source->filename() === $item->filename];
        }

        self::assertSame([[1, 'first', 1, true], [2, 'second', 1, true], [3, 'second', 1, true]], $items);
        self::assertStringEndsWith(':1', $source->positionLabel());
        self::assertStringStartsWith($second, $source->positionLabel());
    }

    public function testMissingFileIsReportedAndSkipped(): void
    {
        $good   = $this->tempFile('5');
        $source = $this->source('', ['/nonexistent/in.json', $good]);

        self::assertSame([[5, 0]], $this->drain($source));
        self::assertTrue($source->hadUnreadableFile());
        self::assertSame(["jq: error: Could not open /nonexistent/in.json: No such file or directory\n"], $this->warnings);
    }

    public function testNoUnreadableFileByDefault(): void
    {
        $source = $this->source('1');
        $this->drain($source);

        self::assertFalse($source->hadUnreadableFile());
    }

    public function testDashIsStandardInput(): void
    {
        $file   = $this->tempFile('2');
        $source = $this->source('1', [$file, '-']);

        self::assertSame([[2, 0], [1, 0]], $this->drain($source));
    }

    public function testParseErrorEndsTheInputAndEarlierValuesAreKept(): void
    {
        $source = $this->source("1\nfoo\n2");

        $first = $source->fetch();
        $error = $source->fetch();

        self::assertInstanceOf(InputItem::class, $first);
        self::assertSame(1, $first->value);
        self::assertInstanceOf(InputItem::class, $error);
        self::assertTrue($error->isError());
        self::assertTrue($error->fatal);
        self::assertSame('Invalid literal at line 3, column 0', $error->error);
        self::assertNull($source->fetch());
    }

    public function testParseErrorStopsLaterFilesToo(): void
    {
        $bad   = $this->tempFile('foo');
        $later = $this->tempFile('2');
        $items = $this->items($this->source('', [$bad, $later]));

        self::assertCount(1, $items);
        self::assertTrue($items[0]->isError());
    }

    public function testUnfinishedContainerIsAParseError(): void
    {
        $items = $this->items($this->source("[1,\n2"));

        self::assertCount(1, $items);
        self::assertSame('Unfinished JSON term at EOF at line 2, column 1', $items[0]->error ?? null);
    }

    public function testNextRaisesAParseErrorAsAJqError(): void
    {
        $source = $this->source('1 foo');
        self::assertSame(1, $source->next());

        $this->expectExceptionObject(JqException::fromMessage('Invalid literal at line 1, column 6'));
        $source->next();
    }

    public function testStrayClosingBracketIsAParseError(): void
    {
        $items = $this->items($this->source('1 ]'));

        self::assertCount(2, $items);
        self::assertTrue($items[1]->isError());
    }

    public function testSlurpGivesOneArray(): void
    {
        $items = $this->items($this->source("1 2\n[3]", [], new CliOptions(slurp: true)));

        self::assertCount(1, $items);
        self::assertSame([1, 2, [3]], $items[0]->value);
        self::assertSame(1, $items[0]->line);
    }

    public function testSlurpOfNothingIsAnEmptyArray(): void
    {
        $items = $this->items($this->source('', [], new CliOptions(slurp: true)));

        self::assertCount(1, $items);
        self::assertSame([], $items[0]->value);
    }

    public function testSlurpStopsAtAParseError(): void
    {
        $items = $this->items($this->source('1 foo', [], new CliOptions(slurp: true)));

        self::assertCount(1, $items);
        self::assertTrue($items[0]->isError());
    }

    public function testSlurpAcrossFiles(): void
    {
        $first  = $this->tempFile('1');
        $second = $this->tempFile('2');
        $items  = $this->items($this->source('', [$first, $second], new CliOptions(slurp: true)));

        self::assertSame([1, 2], $items[0]->value);
        self::assertSame($second, $items[0]->filename);
    }

    public function testRawInputYieldsLines(): void
    {
        $values = $this->drain($this->source("a\nb\n\nc", [], new CliOptions(rawInput: true)));

        self::assertSame([['a', 1], ['b', 2], ['', 3], ['c', 3]], $values);
    }

    public function testRawInputKeepsNulBytes(): void
    {
        $values = $this->drain($this->source("a\0b\nc\0d\ne", [], new CliOptions(rawInput: true)));

        self::assertSame([["a\0b", 1], ["c\0d", 2], ['e', 2]], $values);
    }

    public function testRawInputWithTrailingNewlineHasNoExtraLine(): void
    {
        self::assertSame([['a', 1]], $this->drain($this->source("a\n", [], new CliOptions(rawInput: true))));
    }

    public function testRawInputReplacesInvalidUtf8(): void
    {
        $values = $this->drain($this->source("a\xffb\n", [], new CliOptions(rawInput: true)));

        self::assertSame([["a\u{FFFD}b", 1]], $values);
    }

    public function testRawSlurpIsTheWholeText(): void
    {
        $items = $this->items($this->source("a\0b\nc\0d\ne", [], new CliOptions(rawInput: true, slurp: true)));

        self::assertCount(1, $items);
        self::assertSame("a\0b\nc\0d\ne", $items[0]->value);
    }

    public function testRawSlurpOfNothingIsTheEmptyString(): void
    {
        $items = $this->items($this->source('', [], new CliOptions(rawInput: true, slurp: true)));

        self::assertSame('', $items[0]->value);
    }

    public function testRawSlurpJoinsFiles(): void
    {
        $first  = $this->tempFile("a\n");
        $second = $this->tempFile('b');
        $items  = $this->items($this->source('', [$first, $second], new CliOptions(rawInput: true, slurp: true)));

        self::assertSame("a\nb", $items[0]->value);
    }

    public function testStreamEvents(): void
    {
        $values = $this->drain($this->source("[1]\n[2]", [], new CliOptions(stream: true)));

        self::assertSame([[[[0], 1], 1], [[[0]], 1], [[[0], 2], 1], [[[0]], 1]], array_map(static fn (array $pair): array => $pair, $values));
    }

    public function testStreamLineNumbersFollowTheEvents(): void
    {
        $items = $this->items($this->source("[1,\n2]\n3", [], new CliOptions(stream: true)));

        self::assertSame([1, 2, 2, 2], array_map(static fn (InputItem $item): int => $item->line, \array_slice($items, 0, 4)));
    }

    public function testStreamErrorBecomesAnErrorItem(): void
    {
        $items = $this->items($this->source('[1,', [], new CliOptions(stream: true)));

        self::assertCount(2, $items);
        self::assertTrue($items[1]->isError());
        self::assertTrue($items[1]->fatal);
        self::assertSame('Unfinished JSON term at EOF at line 1, column 3', $items[1]->error);
    }

    public function testStreamErrorsBecomesAValueAndEndsTheStream(): void
    {
        $items = $this->items($this->source('[1,', [], new CliOptions(stream: true, streamErrors: true)));

        self::assertCount(2, $items);
        self::assertFalse($items[1]->isError());
        self::assertSame(['Unfinished JSON term at EOF at line 1, column 3', [1]], $items[1]->value);
    }

    public function testStreamWithSlurpCollectsEvents(): void
    {
        $items = $this->items($this->source('[1][2]', [], new CliOptions(slurp: true, stream: true)));

        self::assertCount(1, $items);
        self::assertSame([[[0], 1], [[0]], [[0], 2], [[0]]], $items[0]->value);
    }

    public function testSeqSkipsDamagedRecords(): void
    {
        $text  = "1\x1e2 3\n[0,1\x1e[4,5]true\"ab\"{\"c\":4\x1e{}{\"d\":5,\"e\":6\"\x1efalse\n";
        $items = $this->items($this->source($text, [], new CliOptions(seq: true)));

        $values   = [];
        $messages = [];
        foreach ($items as $item) {
            if ($item->isError()) {
                self::assertFalse($item->fatal);
                $messages[] = $item->error;

                continue;
            }

            $values[] = $item->value;
        }

        self::assertCount(3, $messages);
        self::assertStringStartsWith('Truncated value at line 2', $messages[0] ?? '');
        self::assertSame(1, $values[0]);
        self::assertContains(false, $values);
        self::assertContains(true, $values);
    }

    public function testSeqErrorAtTheEndReportsTheWholeLineCount(): void
    {
        $items = $this->items($this->source("[1\n", [], new CliOptions(seq: true)));

        self::assertCount(1, $items);
        self::assertTrue($items[0]->isError());
        self::assertFalse($items[0]->fatal);
        self::assertSame(1, $items[0]->line);
    }

    public function testSeqResumesAtTheNextSeparatorWithoutLooping(): void
    {
        $items = $this->items($this->source(str_repeat("\x1e[", 50), [], new CliOptions(seq: true)));

        self::assertCount(50, $items);
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

        return new InputSource(
            $files,
            $stream,
            new JqApplicationFakeDecoder(),
            $options ?? new CliOptions(),
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );
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
     * @return list<array{mixed, int}> value and line of every item
     */
    private function drain(InputSource $source): array
    {
        $pairs = [];
        foreach ($this->items($source) as $item) {
            self::assertFalse($item->isError(), (string)$item->error);
            $pairs[] = [$item->value, $item->line];
        }

        return $pairs;
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
