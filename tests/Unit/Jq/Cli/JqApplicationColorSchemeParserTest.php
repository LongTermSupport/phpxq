<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\ColorSchemeParser;
use LTS\PhpXq\Json\ColorScheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationColorSchemeParserTest extends TestCase
{
    public function testDefaultPalette(): void
    {
        $scheme = ColorSchemeParser::defaults();

        self::assertSame("\e[0;90m", $scheme->null);
        self::assertSame("\e[0;39m", $scheme->false);
        self::assertSame("\e[0;39m", $scheme->true);
        self::assertSame("\e[0;39m", $scheme->number);
        self::assertSame("\e[0;32m", $scheme->string);
        self::assertSame("\e[1;39m", $scheme->array);
        self::assertSame("\e[1;39m", $scheme->object);
        self::assertSame("\e[1;34m", $scheme->objectKey);
    }

    public function testEmptySpecKeepsTheDefaults(): void
    {
        self::assertEquals(ColorSchemeParser::defaults(), ColorSchemeParser::parse(''));
    }

    public function testFirstFieldOverridesNull(): void
    {
        $scheme = ColorSchemeParser::parse('4;31');

        self::assertInstanceOf(ColorScheme::class, $scheme);
        self::assertSame("\e[4;31m", $scheme->null);
        self::assertSame("\e[0;39m", $scheme->false);
    }

    public function testEmptyFieldIsThePlainSequence(): void
    {
        self::assertSame("\e[m", ColorSchemeParser::parse(':')?->null);
        self::assertSame("\e[m", ColorSchemeParser::parse('::::::::')?->objectKey);
    }

    public function testAllEightFields(): void
    {
        $scheme = ColorSchemeParser::parse('0;30:0;31:0;32:0;33:0;34:1;35:1;36:1;37');

        self::assertInstanceOf(ColorScheme::class, $scheme);
        self::assertSame(
            ["\e[0;30m", "\e[0;31m", "\e[0;32m", "\e[0;33m", "\e[0;34m", "\e[1;35m", "\e[1;36m", "\e[1;37m"],
            [$scheme->null, $scheme->false, $scheme->true, $scheme->number, $scheme->string, $scheme->array, $scheme->object, $scheme->objectKey],
        );
    }

    public function testPartialFieldsKeepTheRestOfTheDefaults(): void
    {
        $scheme = ColorSchemeParser::parse('0;37:0;31:0;35:0;34:0;36');

        self::assertInstanceOf(ColorScheme::class, $scheme);
        self::assertSame("\e[0;36m", $scheme->string);
        self::assertSame("\e[1;39m", $scheme->array);
        self::assertSame("\e[1;34m", $scheme->objectKey);
    }

    public function testTruecolorFieldsAndATrailingColon(): void
    {
        $spec   = '38;2;255;173;173:38;2;255;214;165:38;2;253;255;182:38;2;202;255;191:38;2;155;246;255:38;2;160;196;255:38;2;189;178;255:38;2;255;198;255:';
        $scheme = ColorSchemeParser::parse($spec);

        self::assertInstanceOf(ColorScheme::class, $scheme);
        self::assertSame("\e[38;2;255;173;173m", $scheme->null);
        self::assertSame("\e[38;2;255;198;255m", $scheme->objectKey);
    }

    public function testFieldsAfterTheEighthAreIgnored(): void
    {
        self::assertNotNull(ColorSchemeParser::parse('1:1:1:1:1:1:1:1:bogus'));
    }

    #[DataProvider('provideInvalid')]
    public function testOneBadFieldRejectsTheWholeSpec(string $spec): void
    {
        self::assertNull(ColorSchemeParser::parse($spec));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'slash' => ['/'];

        yield 'bracket' => ['[30'];

        yield 'trailing m' => ['30m'];

        yield 'bad second field' => ['30:31m:32'];

        yield 'star' => ['30:*:31'];

        yield 'word' => ['invalid'];
    }
}
