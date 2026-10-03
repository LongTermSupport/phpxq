<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

    public function testChars(): void
    {
        self::assertSame([], Text::chars(''));
        self::assertSame(['a', 'é', '😀'], Text::chars('aé😀'));
    }
}
