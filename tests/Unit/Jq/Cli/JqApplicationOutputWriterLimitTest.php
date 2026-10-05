<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\InputSegmenter;
use LTS\PhpXq\Jq\Cli\OutputWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The default flush threshold of the output writer, and where the input segmenter cuts a stream that arrives
 * in pieces.
 *
 * @internal
 */
final class JqApplicationOutputWriterLimitTest extends TestCase
{
    public function testTheDefaultLimitIsSixtyFiveThousandFiveHundredThirtySixBytes(): void
    {
        $stream = $this->memory();
        $writer = new OutputWriter($stream);

        $writer->write(str_repeat('a', 65535));

        $before = $this->contents($stream);

        $writer->write('a');
        $after = $this->contents($stream);

        self::assertSame('', $before);
        self::assertSame(65536, \strlen($after));
    }

    public function testASegmentEndsAtTheLastNewlineBetweenValues(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertSame("1\n2\n", $segmenter->push("1\n2\n"));
        self::assertSame("[1,\n2]\n", $segmenter->push("[1,\n2]\n3"));
        self::assertSame('3', $segmenter->finish());
    }

    public function testAStringSplitRightAfterABackslashKeepsItsEscape(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push('"a\\'));
        self::assertSame("\"a\\nb\"\n", $segmenter->push("nb\"\n"));
    }

    public function testAScalarSplitAcrossChunksIsOneValue(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push('12'));
        self::assertSame("1234\n", $segmenter->push("34\n"));
    }

    public function testRawInputIsCutAtAnyNewline(): void
    {
        $segmenter = new InputSegmenter(false);

        self::assertSame("a\n", $segmenter->push("a\nb"));
        self::assertNull($segmenter->push('c'));
        self::assertSame("bc\n", $segmenter->push("\nd"));
        self::assertSame('d', $segmenter->finish());
    }

    public function testNewlinesInsideAnObjectAndItsStringsDoNotCut(): void
    {
        $segmenter = new InputSegmenter(true);

        self::assertNull($segmenter->push("{\"a\":\n\"x\ny\""));
        self::assertSame("{\"a\":\n\"x\ny\"}\n", $segmenter->push("}\n"));
    }

    /**
     * @return resource
     */
    private function memory()
    {
        $stream = fopen('php://memory', 'w+b');
        if (false === $stream) {
            throw new RuntimeException('no memory stream');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        return (string)stream_get_contents($stream);
    }
}
