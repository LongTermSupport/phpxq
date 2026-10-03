<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use LTS\PhpXq\Json\Codec\JqColors;
use LTS\PhpXq\Json\ColorScheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqColorsTest extends TestCase
{
    public function testEmptySpecKeepsDefaults(): void
    {
        self::assertEquals(ColorScheme::default(), JqColors::parse(''));
    }

    public function testSingleFieldOverridesNullOnly(): void
    {
        $scheme = JqColors::parse('4;31');

        self::assertNotNull($scheme);
        self::assertSame("\e[4;31m", $scheme->null);
        self::assertSame(ColorScheme::default()->false, $scheme->false);
        self::assertSame(ColorScheme::default()->objectKey, $scheme->objectKey);
    }

    public function testEmptyFieldsSelectTheBareEscape(): void
    {
        $scheme = JqColors::parse(':');

        self::assertNotNull($scheme);
        self::assertSame("\e[m", $scheme->null);
        self::assertSame("\e[m", $scheme->false);
        self::assertSame(ColorScheme::default()->true, $scheme->true);
    }

    public function testExtraFieldsAreIgnored(): void
    {
        $scheme = JqColors::parse('::::::::');

        self::assertNotNull($scheme);
        self::assertSame("\e[m", $scheme->null);
        self::assertSame("\e[m", $scheme->objectKey);
    }

    public function testAllEightFields(): void
    {
        $scheme = JqColors::parse('0;30:0;31:0;32:0;33:0;34:1;35:1;36:1;37');

        self::assertEquals(
            new ColorScheme("\e[0;30m", "\e[0;31m", "\e[0;32m", "\e[0;33m", "\e[0;34m", "\e[1;35m", "\e[1;36m", "\e[1;37m"),
            $scheme,
        );
    }

    public function testTrueColourFields(): void
    {
        $scheme = JqColors::parse('38;2;255;173;173:38;2;255;214;165');

        self::assertNotNull($scheme);
        self::assertSame("\e[38;2;255;173;173m", $scheme->null);
        self::assertSame("\e[38;2;255;214;165m", $scheme->false);
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidSpecIsRejected(string $spec): void
    {
        self::assertNull(JqColors::parse($spec));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'slash'          => ['/'];
        yield 'bracket'        => ['[30'];
        yield 'trailing m'     => ['30m'];
        yield 'second invalid' => ['30:31m:32'];
        yield 'star'           => ['30:*:31'];
        yield 'word'           => ['invalid'];
        yield 'too long'       => [str_repeat('1', 40)];
    }
}
