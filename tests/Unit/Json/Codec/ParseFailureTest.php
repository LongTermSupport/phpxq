<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\Codec\ParseFailure;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ParseFailureTest extends TestCase
{
    public function testDescribeUsesLineAndColumnOfConsumedBytes(): void
    {
        $failure = new ParseFailure('Invalid numeric literal', 5);

        self::assertSame('Invalid numeric literal at line 1, column 5', $failure->describe("{'a':1}", 0));
    }

    public function testNewlineStartsANewLineAtColumnZero(): void
    {
        $failure = new ParseFailure('Unfinished JSON term', 3, eof: true);

        self::assertSame('Unfinished JSON term at EOF at line 2, column 0', $failure->describe("[1\n", 0));
    }

    public function testColumnCountsBytesSinceLastNewline(): void
    {
        $failure = new ParseFailure('Invalid numeric literal', 8);

        self::assertSame('Invalid numeric literal at line 3, column 2', $failure->describe("[\n1,\nx]", 0));
    }

    public function testBaseOffsetIsNotCounted(): void
    {
        $failure = new ParseFailure('Invalid numeric literal', 5);

        self::assertSame('Invalid numeric literal at line 1, column 2', $failure->describe("\xEF\xBB\xBFx]", 3));
    }

    public function testFlagsAreExposed(): void
    {
        $failure = new ParseFailure('Truncated value', 4, onRs: true);

        self::assertTrue($failure->onRs);
        self::assertFalse($failure->eof);
        self::assertSame(4, $failure->consumed);
        self::assertSame('Truncated value', $failure->getMessage());
    }
}
