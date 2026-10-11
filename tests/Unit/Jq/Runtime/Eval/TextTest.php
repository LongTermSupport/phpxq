<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Text::class)]
final class TextTest extends TestCase
{
    public function testIsAscii(): void
    {
        self::assertTrue(Text::isAscii(''));
        self::assertTrue(Text::isAscii('plain text 123'));
        self::assertFalse(Text::isAscii('café'));
    }

    public function testLengthCountsCodepoints(): void
    {
        self::assertSame(0, Text::length(''));
        self::assertSame(1, Text::length('a'));
        self::assertSame(2, Text::length('ab'));
        self::assertSame(1, Text::length('é'));
        self::assertSame(3, Text::length('abc'));
        self::assertSame(4, Text::length('aé☆😀'));
    }

    public function testSliceByCodepoint(): void
    {
        self::assertSame('bc', Text::slice('abcd', 1, 3));
        self::assertSame('é☆', Text::slice('aé☆😀', 1, 3));
        self::assertSame('😀', Text::slice('aé☆😀', 3, 4));
        self::assertSame('', Text::slice('abc', 2, 2));
        self::assertSame('', Text::slice('abc', 3, 1));
    }

    public function testSlicesOfALongMixedWidthTextMatchACodepointReference(): void
    {
        $text   = str_repeat("a\u{e9}\u{2606}\u{1f600}bc", 50);
        $length = mb_strlen($text, 'UTF-8');

        self::assertSame($length, Text::length($text));
        for ($start = 0; $start <= $length; $start += 7) {
            foreach ([$start, $start + 1, $start + 63, $start + 64, $start + 65, $length] as $end) {
                $end = min($end, $length);
                self::assertSame(mb_substr($text, $start, $end - $start, 'UTF-8'), Text::slice($text, $start, $end), \sprintf('[%d:%d]', $start, $end));
            }
        }
    }

    public function testSlicingDifferentTextsInTurnGivesEachItsOwnCodepoints(): void
    {
        $first  = str_repeat("\u{e9}x", 100);
        $second = str_repeat("y\u{1f600}", 100);

        self::assertSame("\u{e9}x\u{e9}", Text::slice($first, 0, 3));
        self::assertSame("\u{1f600}y", Text::slice($second, 1, 3));
        self::assertSame("x\u{e9}", Text::slice($first, 199, 200) . Text::slice($first, 0, 1));
        self::assertSame(200, Text::length($second));
        self::assertSame('y', Text::slice($second, 198, 199));
        self::assertSame("\u{e9}x\u{e9}x", Text::slice(str_repeat("\u{e9}x", 2), 0, 4));
    }

    public function testChars(): void
    {
        self::assertSame([], Text::chars(''));
        self::assertSame(['a', 'é', '😀'], Text::chars('aé😀'));
    }
}
