<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\InputSegmenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationInputSegmenterTest extends TestCase
{
    public function testAJsonLineIsReleasedAsSoonAsItsNewlineArrives(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push('{"a":1}'));
        self::assertSame("{\"a\":1}\n", $segmenter->push("\n"));
        self::assertNull($segmenter->push('{"b":'));
        self::assertNull($segmenter->push("2\n"));
        self::assertSame("{\"b\":2\n}\n", $segmenter->push("}\n"));
        self::assertSame('', $segmenter->finish());
    }

    public function testWholeLinesAreCutWithoutScanningAndAPartialLineIsKept(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertSame("{\"a\":1}\n[2]\n", $segmenter->push("{\"a\":1}\n[2]\n{\"b\""));
        self::assertSame("{\"b\":3}\n", $segmenter->push(":3}\n"));
    }

    public function testAValueSpanningLinesIsNotCutByTheFastPath(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push("{\n\"a\": 1,\n"));
        self::assertNull($segmenter->push("\"b\": [\n1\n"));
        self::assertSame("{\n\"a\": 1,\n\"b\": [\n1\n]}\n", $segmenter->push("]}\n"));
    }

    public function testAnUnfinishedValueIsHeldBack(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertSame("1\n2\n", $segmenter->push("1\n2\n[3,\n"));
        self::assertNull($segmenter->push('4]'));
        self::assertSame("[3,\n4]", $segmenter->finish());
    }

    public function testAStringSpanningChunksWithABackslashAtTheEdge(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push('"a\\'));
        self::assertNull($segmenter->push("\"\n"));
        self::assertSame("\"a\\\"\nb\"\n", $segmenter->push("b\"\n"));
    }

    public function testBracketsInsideStringsDoNotCountAsDepth(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push("{\"a\":\"}\n"));
        self::assertSame("{\"a\":\"}\n\"}\n", $segmenter->push("\"}\n"));
    }

    public function testAScalarIsNotClosedByTheEndOfAChunk(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push('12'));
        self::assertSame("1234\n", $segmenter->push("34\n"));
    }

    public function testStrayClosersDoNotStallTheSplit(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertSame("]}:,\n", $segmenter->push("]}:,\n"));
    }

    public function testTheRemainderIsReturnedAtTheEnd(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push('{"a":1}'));
        self::assertSame('{"a":1}', $segmenter->finish());
        self::assertSame('', $segmenter->finish());
    }

    #[DataProvider('provideLineChunks')]
    public function testRawLinesAreCutAtTheLastNewline(string $chunk, ?string $released, string $remainder): void
    {
        $segmenter = new InputSegmenter(false);

        self::assertSame($released, $segmenter->push($chunk));
        self::assertSame($remainder, $segmenter->finish());
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function provideLineChunks(): iterable
    {
        yield 'no newline' => ['abc', null, 'abc'];

        yield 'one line' => ["abc\n", "abc\n", ''];

        yield 'line and a half' => ["abc\nde", "abc\n", 'de'];

        yield 'blank lines' => ["\n\n", "\n\n", ''];
    }

    #[DataProvider('provideSplits')]
    public function testSplittingNeverChangesTheConcatenation(string $text): void
    {
        for ($size = 1; $size <= 7; ++$size) {
            $segmenter = new InputSegmenter(true);
            $joined    = '';
            foreach (str_split($text, $size) as $chunk) {
                $pushed = $segmenter->push($chunk);
                if (null !== $pushed) {
                    $joined .= $pushed;
                }
            }

            self::assertSame($text, $joined . $segmenter->finish());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSplits(): iterable
    {
        yield 'ndjson' => ["{\"a\":1}\n{\"b\":[1,2]}\n\"x\\\"y\"\n123\n"];

        yield 'pretty' => ["{\n  \"a\": [\n    1,\n    \"]\"\n  ]\n}\n{\n}\n"];

        yield 'scalars on one line' => ["1 2 3\ntrue null\n"];

        yield 'no trailing newline' => ["[1]\n[2]"];

        yield 'blank lines and CRLF' => ["{\"a\":1}\r\n\r\n\n{\"b\":2}\r\n[\n1\n]\n"];

        yield 'byte order mark' => ["\xEF\xBB\xBF{\"a\":1}\n{\"b\":2}\n"];

        yield 'invalid text' => ["]]\n{\"a\":}\n\"x\ny\"\n"];
    }
}
