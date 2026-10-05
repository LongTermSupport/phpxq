<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\CodepointCursor;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CodepointCursorTest extends TestCase
{
    public function testLength(): void
    {
        self::assertSame(0, CodepointCursor::length(''));
        self::assertSame(3, CodepointCursor::length('abc'));
        self::assertSame(3, CodepointCursor::length("a\u{e9}\u{20ac}"));
        self::assertSame(2, CodepointCursor::length("\u{1F600}b"));
    }

    public function testAsciiOffsetsAreUnchanged(): void
    {
        $cursor = new CodepointCursor('abcdef', true);

        self::assertSame(4, $cursor->offset(4));
        self::assertSame(1, $cursor->offset(1));
    }

    public function testOffsetsMoveForwardAndBackward(): void
    {
        $subject = "a\u{e9}b\u{20ac}c\u{1F600}d";
        $cursor  = new CodepointCursor($subject, false);

        self::assertSame(0, $cursor->offset(0));
        self::assertSame(3, $cursor->offset(4));
        self::assertSame(5, $cursor->offset(8));
        self::assertSame(1, $cursor->offset(1));
        self::assertSame(1, $cursor->offset(1));
        self::assertSame(7, $cursor->offset(13));
        self::assertSame(2, $cursor->offset(3));
        self::assertSame(6, $cursor->offset(12));
    }

    public function testMeasureCountsCodepointsOnlyForNonAsciiSubjects(): void
    {
        self::assertSame(3, new CodepointCursor('abc', true)->measure('abc'));
        self::assertSame(2, new CodepointCursor("\u{e9}\u{20ac}", false)->measure("\u{e9}\u{20ac}"));
        self::assertSame(0, new CodepointCursor('', false)->measure(''));
    }

    public function testCharacterWidth(): void
    {
        $subject = "a\u{e9}\u{20ac}\u{1F600}";

        self::assertSame(1, CodepointCursor::characterWidth($subject, 0));
        self::assertSame(2, CodepointCursor::characterWidth($subject, 1));
        self::assertSame(3, CodepointCursor::characterWidth($subject, 3));
        self::assertSame(4, CodepointCursor::characterWidth($subject, 6));
        self::assertSame(1, CodepointCursor::characterWidth($subject, 10));
    }
}
